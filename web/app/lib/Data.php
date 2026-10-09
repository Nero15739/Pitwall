<?php
/* Read side for the public pages: the compiled JSON in storage/cache, loaded once per request. */
declare(strict_types=1);

namespace PitWall;

final class Data
{
    private static array $memo = [];

    private static function load(string $file): ?array
    {
        return self::$memo[$file] ??= Files::readJson(Paths::cache() . '/' . $file);
    }

    public static function index(): ?array
    {
        return self::load('seasons.json');
    }

    public static function season(string $slug): ?array
    {
        foreach (self::index()['seasons'] ?? [] as $s) {
            if ($s['slug'] === $slug) return self::load($s['file']);
        }
        return null;
    }

    public static function tracks(): array
    {
        return self::load('tracks.json')['tracks'] ?? [];
    }

    /** Changes whenever the compiler publishes. */
    public static function version(): string
    {
        return (string) (self::index()['generated'] ?? 'none');
    }
}
