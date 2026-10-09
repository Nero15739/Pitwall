<?php
/* Settings: defaults here, overridden by config.php next to index.php (see config.sample.php). */
declare(strict_types=1);

namespace PitWall;

final class Config
{
    private static ?array $c = null;

    private static function defaults(): array
    {
        return [
            'site_name' => 'Pit Wall',
            'db' => ['driver' => 'sqlite', 'path' => null],   // null = storage/pitwall.sqlite
            'storage_dir' => null,                             // null = see Paths::storageDir()
            'track_map_source' => 'https://raw.githubusercontent.com/xikxp1/iRaceHUD/main/static/track_info_data/',
            'api_rate_limit' => 120,                           // requests per key per minute
            'max_upload_mb' => 10,
            'session_hours' => 12,
            'debug' => false,
        ];
    }

    public static function all(): array
    {
        if (self::$c !== null) return self::$c;
        $c = self::defaults();
        $file = Paths::root('config.php');
        if (is_file($file)) {
            $user = require $file;
            if (is_array($user)) $c = array_replace_recursive($c, $user);
        }
        return self::$c = $c;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $v = self::all();
        foreach (explode('.', $key) as $k) {
            if (!is_array($v) || !array_key_exists($k, $v)) return $default;
            $v = $v[$k];
        }
        return $v ?? $default;
    }
}
