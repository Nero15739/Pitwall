<?php
declare(strict_types=1);

namespace PitWall;

final class Files
{
    /** Write via temp file + rename so a reader never sees a half-written file. */
    public static function writeJson(string $path, mixed $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $tmp = $path . '.' . getmypid() . '.' . bin2hex(random_bytes(3)) . '.tmp';
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        file_put_contents($tmp, $json, LOCK_EX);
        if (!@rename($tmp, $path)) {
            // Windows can refuse to replace a file that's open; fall back to copy
            copy($tmp, $path);
            @unlink($tmp);
        }
        if (function_exists('opcache_invalidate')) @opcache_invalidate($path, true);
    }

    public static function readJson(string $path): ?array
    {
        if (!is_file($path)) return null;
        $s = file_get_contents($path);
        if ($s === false || $s === '') return null;
        $v = json_decode($s, true, 512);
        return is_array($v) ? $v : null;
    }
}
