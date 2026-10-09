<?php
declare(strict_types=1);

namespace PitWall;

/** Where things live. The web root holds index.php; app/ and storage/ sit beside it. */
final class Paths
{
    public static function root(string $rel = ''): string
    {
        return PITWALL_ROOT . ($rel === '' ? '' : '/' . $rel);
    }

    public static function app(string $rel = ''): string
    {
        return PITWALL_ROOT . '/app' . ($rel === '' ? '' : '/' . $rel);
    }

    /** Where the database, published data, sessions and logs live. Installed straight into a
        host's public_html (as on Hostinger), they go one level up, out of the web root, so they
        stay private even if .htaccess rules are ever lost. Otherwise storage/ beside index.php. */
    public static function storageDir(): string
    {
        $configured = Config::get('storage_dir');
        if ($configured) return rtrim((string) $configured, '/\\');
        $local = PITWALL_ROOT . '/storage';
        $up = dirname(PITWALL_ROOT) . '/pitwall-storage';
        if (is_dir($local)) return $local; // an existing install keeps its data where it is
        if (strtolower(basename(PITWALL_ROOT)) === 'public_html' && (is_dir($up) || is_writable(dirname(PITWALL_ROOT)))) return $up;
        return $local;
    }

    public static function storageIsOutside(): bool
    {
        return !str_starts_with(str_replace('\\', '/', self::storageDir()), str_replace('\\', '/', PITWALL_ROOT) . '/');
    }

    public static function storage(string $rel = ''): string
    {
        static $dir = null;
        if ($dir === null) {
            $dir = self::storageDir();
            if (!is_dir($dir)) mkdir($dir, 0775, true);
            // keep storage private even if .htaccess rules are lost
            if (!is_file("{$dir}/.htaccess")) {
                @file_put_contents("{$dir}/.htaccess", "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
            }
            if (!is_file("{$dir}/index.html")) @file_put_contents("{$dir}/index.html", '');
        }
        return $dir . ($rel === '' ? '' : '/' . $rel);
    }

    public static function cache(): string
    {
        $d = self::storage('cache');
        if (!is_dir($d)) mkdir($d, 0775, true);
        return $d;
    }
}
