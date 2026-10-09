<?php
/* Track layouts: iRacing's official SVG maps, as mirrored (with lap-distance calibration) by the
   open-source iRaceHUD project. Fetched once per track and cached in the database. A failed fetch
   is retried at most once a day and never fails a compile. */
declare(strict_types=1);

namespace PitWall;

final class TrackMaps
{
    private const RETRY_S = 24 * 3600;
    private const SETTINGS_MAX_AGE_S = 30 * 24 * 3600;
    private const SETTINGS_KEY = -1; // the shared calibration file lives in the cache under this id

    /**
     * @param callable $get   fn(int $id): ?array   cached entry ['fetched' => iso, 'map' => ?array, 'error' => ?string]
     * @param callable $put   fn(int $id, array $entry): void
     * @param array $overrides  track id → partial map (offset / direction fixes)
     * @return array<int, ?array> track id → map
     */
    public static function load(array $ids, string $source, callable $get, callable $put, array $overrides, callable $log, bool $offline = false): array
    {
        $out = [];
        $settings = null;
        foreach ($ids as $id) {
            $entry = $get($id);
            $stale = !$entry || (!$entry['map'] && time() - strtotime($entry['fetched']) > self::RETRY_S);
            if ($stale && !$offline) {
                try {
                    $settings ??= self::settings($source, $get, $put, $log);
                    $active = self::fetch("{$source}active/{$id}.svg");
                    try { $sf = self::fetch("{$source}start_finish/{$id}.svg"); } catch (\Throwable) { $sf = null; }
                    $s = $settings[(string) $id] ?? [];
                    $path = $s['customTrackPath'] ?? self::firstPath($active);
                    if (!$path) throw new \RuntimeException('no path in SVG');
                    preg_match('/viewBox="([^"]+)"/', $active, $vb);
                    $sfPath = $sf && preg_match('/<path[^>]*\sd="([^"]+)"/', $sf, $m) ? $m[1] : null;
                    $entry = [
                        'fetched' => gmdate('Y-m-d\TH:i:s\Z'),
                        'map' => [
                            'viewBox' => $vb[1] ?? '0 0 1920 1080',
                            'path' => $path,
                            'sf' => $sfPath,
                            'offset' => $s['offset'] ?? 0,
                            'direction' => ($s['direction'] ?? 1) === -1 ? -1 : 1,
                        ],
                        'error' => null,
                    ];
                    $log("fetched track map {$id}");
                } catch (\Throwable $e) {
                    $entry = ['fetched' => gmdate('Y-m-d\TH:i:s\Z'), 'map' => $entry['map'] ?? null, 'error' => $e->getMessage()];
                    $log("track map {$id} unavailable: {$e->getMessage()}");
                }
                $put($id, $entry);
            }
            $map = $entry['map'] ?? null;
            $out[$id] = $map && self::safe($map) ? array_merge($map, array_map('floatval', $overrides[(string) $id] ?? [])) : null;
        }
        return $out;
    }

    /** Maps come from a third party: only plain SVG path data and numbers get through to a page. */
    private static function safe(array $m): bool
    {
        $path = '/^[MmLlHhVvCcSsQqTtAaZz0-9.,eE+\-\s]+$/';
        return is_string($m['path'] ?? null) && preg_match($path, $m['path'])
            && (($m['sf'] ?? null) === null || (is_string($m['sf']) && preg_match($path, $m['sf'])))
            && is_string($m['viewBox'] ?? null) && preg_match('/^[0-9.\-\s,]+$/', $m['viewBox'])
            && is_numeric($m['offset'] ?? 0) && in_array((int) ($m['direction'] ?? 1), [1, -1], true);
    }

    /** First <path d="…">, cut at the first close so we keep a single loop (as iRaceHUD does). */
    public static function firstPath(string $svg): ?string
    {
        if (!preg_match('/<path[^>]*\sd="([^"]+)"/', $svg, $m)) return null;
        $loop = trim(preg_replace('/\s+/', ' ', preg_split('/[zZ]/', $m[1])[0]));
        return $loop !== '' ? $loop . 'Z' : null;
    }

    private static function settings(string $source, callable $get, callable $put, callable $log): array
    {
        $cached = $get(self::SETTINGS_KEY);
        if ($cached && $cached['map'] && time() - strtotime($cached['fetched']) < self::SETTINGS_MAX_AGE_S) return $cached['map'];
        try {
            $data = json_decode(self::fetch($source . 'track_settings.json'), true, 64, JSON_THROW_ON_ERROR);
            $put(self::SETTINGS_KEY, ['fetched' => gmdate('Y-m-d\TH:i:s\Z'), 'map' => $data, 'error' => null]);
            return $data;
        } catch (\Throwable $e) {
            $log("track settings unavailable ({$e->getMessage()}); using cached or defaults");
            return $cached['map'] ?? [];
        }
    }

    private static function fetch(string $url): string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_USERAGENT => 'PitWall/3',
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($body === false) throw new \RuntimeException($err ?: "fetch failed: {$url}");
            if ($code >= 400) throw new \RuntimeException("{$code} {$url}");
            return (string) $body;
        }
        $ctx = stream_context_create(['http' => ['timeout' => 10, 'user_agent' => 'PitWall/3', 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $ctx);
        $status = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int) $m[1] : 0;
        if ($body === false || $status >= 400) throw new \RuntimeException(($status ?: 'fetch failed') . " {$url}");
        return $body;
    }
}
