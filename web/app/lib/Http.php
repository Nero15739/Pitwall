<?php
/* Request and response helpers. The site may live at a domain root or in a sub-folder. */
declare(strict_types=1);

namespace PitWall;

final class Http
{
    private static ?string $nonce = null;
    private static ?string $base = null;

    /** URL prefix when installed in a sub-folder ('' at a domain root). */
    public static function base(): string
    {
        if (self::$base !== null) return self::$base;
        $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
        return self::$base = rtrim($dir === '.' ? '' : $dir, '/');
    }

    public static function url(string $path = '/'): string
    {
        return self::base() . '/' . ltrim($path, '/');
    }

    public static function absoluteUrl(string $path = '/'): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return (self::isHttps() ? 'https' : 'http') . '://' . $host . self::url($path);
    }

    /** Asset URL with a version stamp, so browsers cache it for a year and still see changes. */
    public static function asset(string $path): string
    {
        $file = PITWALL_ROOT . '/assets/' . $path;
        $v = is_file($file) ? base_convert((string) filemtime($file), 10, 36) : PITWALL_VERSION;
        return self::url('assets/' . $path) . '?v=' . $v;
    }

    /** Request path below the base, without query string or trailing slash. */
    public static function path(): string
    {
        $p = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
        $base = self::base();
        if ($base !== '' && str_starts_with($p, $base)) $p = substr($p, strlen($base));
        if (str_starts_with($p, '/index.php')) $p = substr($p, 10);
        $p = '/' . trim($p, '/');
        return $p;
    }

    /** A posted form field as a string ('' when missing or sent as an array). */
    public static function post(string $key): string
    {
        $v = $_POST[$key] ?? '';
        return is_string($v) ? $v : '';
    }

    /** A query-string value as a string, or null. */
    public static function query(string $key): ?string
    {
        $v = $_GET[$key] ?? null;
        return is_string($v) ? $v : null;
    }

    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['SERVER_PORT'] ?? null) == 443
            || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    /** The visitor's address. Forwarding headers are easy to fake, so they only count when
        config.php says a proxy (Cloudflare, Hostinger CDN) really sits in front of the site. */
    public static function ip(): string
    {
        if (Config::get('trust_proxy_headers', false)) {
            foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP'] as $h) {
                if (!empty($_SERVER[$h]) && filter_var($_SERVER[$h], FILTER_VALIDATE_IP)) return $_SERVER[$h];
            }
            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $first = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
                if (filter_var($first, FILTER_VALIDATE_IP)) return $first;
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public static function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $v = $_SERVER[$key] ?? ($name === 'Content-Type' ? ($_SERVER['CONTENT_TYPE'] ?? null) : null);
        if ($v === null && function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $val) if (strcasecmp($k, $name) === 0) return $val;
        }
        return $v;
    }

    public static function nonce(): string
    {
        return self::$nonce ??= base64_encode(random_bytes(12));
    }

    public static function securityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-Frame-Options: DENY');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-" . self::nonce() . "'; "
            . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; "
            . "img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
        if (self::isHttps()) header('Strict-Transport-Security: max-age=31536000');
    }

    /** Answer 304 when the browser already has this exact page. */
    public static function etag(string $seed): void
    {
        $tag = '"' . substr(sha1($seed . '|' . PITWALL_VERSION), 0, 20) . '"';
        header('ETag: ' . $tag);
        header('Cache-Control: no-cache');
        $inm = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        if ($inm !== '' && trim(str_replace('W/', '', $inm)) === $tag) {
            http_response_code(304);
            exit;
        }
    }

    public static function json(mixed $data, int $status = 200, array $headers = []): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        foreach ($headers as $k => $v) header("{$k}: {$v}");
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    public static function redirect(string $to, int $code = 303): never
    {
        header('Location: ' . (preg_match('#^https?://#', $to) ? $to : self::url($to)), true, $code);
        exit;
    }
}
