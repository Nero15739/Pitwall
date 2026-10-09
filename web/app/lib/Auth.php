<?php
/* Admin sign-in: one account, a session cookie, CSRF tokens on every form, and login throttling.
   The first account can only be created with the one-time token the site writes into storage/,
   so nobody can claim a fresh install before you do. */
declare(strict_types=1);

namespace PitWall;

final class Auth
{
    private const IP_FAILS = 5;         // per address, per 15 minutes
    private const ALL_FAILS = 100;      // across all addresses, per 15 minutes (high, so nobody can lock you out on purpose)
    private const WINDOW = 900;
    private const TOKEN_FILE = 'INSTALL-TOKEN.txt';

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) ((int) Config::get('session_hours', 12) * 3600));
        $dir = Paths::storage('sessions');
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        session_save_path($dir);
        session_name('pitwall_admin');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => (Http::base() ?: '') . '/admin',
            'secure' => Http::isHttps(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
        $now = time();
        $idle = (int) Config::get('session_hours', 12) * 3600;
        if (isset($_SESSION['uid']) && ($now - ($_SESSION['seen'] ?? 0) > $idle)) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['seen'] = $now;
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function user(Store $store): ?array
    {
        $id = $_SESSION['uid'] ?? null;
        return $id ? $store->userById((int) $id) : null;
    }

    public static function csrf(): string
    {
        return $_SESSION['csrf'] ?? '';
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::csrf()) . '">';
    }

    public static function checkCsrf(): void
    {
        $sent = Http::post('_csrf');
        if ($sent === '' || !hash_equals(self::csrf(), $sent)) {
            throw new UserError('This form expired. Reload the page and try again.', 400);
        }
    }

    /** @return string|null an error to show, or null when signed in */
    public static function login(Store $store, string $username, string $password): ?string
    {
        $ip = Http::ip();
        if ($store->throttled("login:{$ip}", self::IP_FAILS) || $store->throttled('login:*', self::ALL_FAILS)) {
            return 'Too many failed sign-ins. Wait 15 minutes and try again.';
        }
        $u = $store->user($username);
        // verify against a dummy hash when the user is unknown, so timing doesn't reveal usernames
        $hash = $u['password_hash'] ?? '$2y$10$q.fCMxiAFQrH0L2W3o0LdOnl18yhPuXiSyxyjH8lM3/mL4eRDSEta';
        $ok = password_verify($password, $hash) && $u;
        if (!$ok) {
            $store->throttle("login:{$ip}", self::IP_FAILS, self::WINDOW);
            $store->throttle('login:*', self::ALL_FAILS, self::WINDOW);
            $store->log('anon', 'login.fail', mb_substr($username, 0, 64), $ip);
            return 'Wrong username or password.';
        }
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $u['id'];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $store->clearThrottle("login:{$ip}");
        $store->touchLogin((int) $u['id'], password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT) ? password_hash($password, PASSWORD_DEFAULT) : null);
        $store->log('admin:' . $u['username'], 'login', '', $ip);
        return null;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Strict']);
        }
        session_destroy();
    }

    public static function checkPassword(string $pw, string $confirm): ?string
    {
        if (strlen($pw) < 12) return 'Use at least 12 characters for the password.';
        if (strlen($pw) > 200) return 'That password is too long.';
        if ($pw !== $confirm) return 'The two passwords don’t match.';
        return null;
    }

    public static function checkUsername(string $u): ?string
    {
        return preg_match('/^[A-Za-z0-9._-]{3,64}$/', $u) ? null : 'Usernames are 3 to 64 letters, numbers, dots, dashes or underscores.';
    }

    // ---------------------------------------------------------------- first-run token
    public static function installToken(): string
    {
        $f = Paths::storage(self::TOKEN_FILE);
        if (!is_file($f)) {
            $t = bin2hex(random_bytes(12));
            file_put_contents($f, "Pit Wall setup token (single use; deleted once the admin account exists):\n{$t}\n", LOCK_EX);
        }
        preg_match('/^([0-9a-f]{24})$/m', (string) file_get_contents($f), $m);
        return $m[1] ?? '';
    }

    public static function clearInstallToken(): void
    {
        @unlink(Paths::storage(self::TOKEN_FILE));
    }

    // ---------------------------------------------------------------- one-shot messages
    public static function flash(string $type, string $text, array $lines = []): void
    {
        $_SESSION['flash'][] = compact('type', 'text', 'lines');
    }

    public static function takeFlash(): array
    {
        $f = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $f;
    }
}
