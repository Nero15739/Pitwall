<?php
/* Live logs from the Pit Wall Logger app: every incident iRacing counted during a live session,
   placed on the track. Stored as files in storage/live (they stand alone: no race export needed)
   and shown under /live. */
declare(strict_types=1);

namespace PitWall;

final class Live
{
    /** Incident points by what the rise was: iRacing's 1x / 2x / 4x. */
    public const KINDS = ['off track' => 'Off track', 'spin / wall' => 'Spin / wall', 'car contact' => 'Contact'];

    private static function dir(): string
    {
        $d = Paths::storage('live');
        if (!is_dir($d)) mkdir($d, 0775, true);
        return $d;
    }

    /** A file's id is its name without .json; anything else is never read. */
    private static function path(string $id): ?string
    {
        return preg_match('/^live-[a-z0-9]+(?:-[a-z0-9]+)*$/', $id) ? self::dir() . "/{$id}.json" : null;
    }

    /** Check an uploaded live log and store it under a name built from what's in it. */
    public static function save(array $doc): array
    {
        if (($doc['method'] ?? null) !== 'live' || (int) ($doc['version'] ?? 0) !== 1) {
            throw new UserError('This endpoint takes a live log from the Pit Wall Logger ("method": "live", "version": 1).');
        }
        if (!is_array($doc['incidents'] ?? null) || !is_array($doc['drivers'] ?? null)) throw new UserError('The live log has no drivers or incidents list.');
        $sub = (int) ($doc['subsession'] ?? 0);
        $type = preg_replace('/[^a-z0-9]+/', '', strtolower((string) ($doc['session']['type'] ?? 'session'))) ?: 'session';
        $when = strtotime((string) ($doc['started'] ?? '')) ?: time();
        $id = 'live-' . ($sub > 0 ? $sub : gmdate('Ymd-Hi', $when)) . '-s' . (int) ($doc['session']['num'] ?? 0) . "-{$type}";
        $path = self::path($id);
        $old = Files::readJson($path);
        $status = $old === null ? 'created' : ($old == $doc ? 'unchanged' : 'updated');
        if ($status !== 'unchanged') Files::writeJson($path, $doc);
        return ['type' => 'live', 'status' => $status, 'id' => $id, 'incidents' => count($doc['incidents'])];
    }

    /** A stored log as uploaded (Cutoffs::live gives it as shown). */
    public static function load(string $id): ?array
    {
        $p = self::path($id);
        return $p ? Files::readJson($p) : null;
    }

    /** A subsession's race log as uploaded (its last race session, if there were heats). */
    public static function raceLog(int $sub): ?array
    {
        if ($sub <= 0) return null;
        $best = null;
        foreach (glob(self::dir() . "/live-{$sub}-s*-*race*.json") ?: [] as $f) {
            $d = Files::readJson($f);
            if ($d && Cutoffs::isRace($d) && (int) ($d['subsession'] ?? 0) === $sub && (!$best || (int) $d['session']['num'] > (int) $best['session']['num'])) $best = $d;
        }
        return $best;
    }

    /** Every stored session, newest first, with what the list page shows (after any cut-off). */
    public static function all(): array
    {
        $out = [];
        $store = Store::open();
        $cuts = Cutoffs::all($store);
        foreach (glob(self::dir() . '/live-*.json') ?: [] as $f) {
            $d = Files::readJson($f);
            if (!$d) continue;
            $d = Cutoffs::liveFrom($store, $d, $cuts);
            $out[] = [
                'id' => basename($f, '.json'), 'track' => $d['track']['name'] ?? '?', 'type' => $d['session']['type'] ?? '?',
                'started' => $d['started'] ?? null, 'incidents' => count($d['incidents']),
                'points' => array_sum(array_column($d['incidents'], 'delta')), 'by' => $d['recorded_by']['name'] ?? '',
                'view' => $d['view'] ?? '', 'cars' => count($d['drivers']), 'finished' => !empty($d['finished']),
            ];
        }
        usort($out, fn($a, $b) => strcmp((string) $b['started'], (string) $a['started']));
        return $out;
    }

    /** What the session page needs: per-driver totals checked against iRacing's own counts, and the heat map's events. */
    public static function page(array $d): array
    {
        $inc = $d['incidents'];
        $drivers = [];
        foreach ($d['drivers'] as $x) $drivers[$x['car_idx']] = $x;
        $rows = [];
        foreach ($inc as $e) {
            $r = &$rows[$e['car_idx']];
            $r ??= ['name' => $e['name'], 'ai' => (bool) $e['ai'], 'n' => 0, 'points' => 0, 'kinds' => [], 'exact' => 0, 'first' => $e['from']];
            $r['n']++;
            $r['points'] += $e['delta'];
            $r['kinds'][$e['kind']] = ($r['kinds'][$e['kind']] ?? 0) + 1;
            if (!empty($e['off_track_at'])) $r['exact']++;
            unset($r);
        }
        // counted after the race's cut-off: on iRacing's final count, but left out here
        $late = [];
        foreach ($d['cut']['incidents'] ?? [] as $e) $late[$e['car_idx']] = ($late[$e['car_idx']] ?? 0) + $e['delta'];
        foreach ($rows as $car => &$r) {
            $final = $drivers[$car]['incident_count'] ?? null;
            $r['final'] = $final;
            $r['late'] = $late[$car] ?? 0;
            $r['before'] = $r['first'];                              // already on the driver's count when logging began
            $r['missed'] = $final !== null && $final >= 0 ? $final - $r['before'] - $r['points'] - $r['late'] : null;
        }
        unset($r);
        uasort($rows, fn($a, $b) => $b['points'] <=> $a['points'] ?: strcmp($a['name'], $b['name']));

        $points = array_sum(array_column($inc, 'delta'));
        $exact = count(array_filter($inc, fn($e) => !empty($e['off_track_at'])));
        $missed = array_sum(array_map(fn($r) => max(0, (int) $r['missed']), $rows));
        $events = array_map(fn($e) => [
            'pct' => $e['pct'], 'driver' => $e['name'], 'team' => !$e['ai'], 'ai' => (bool) $e['ai'], 'lap' => $e['lap'],
            'kind' => $e['kind'], 'round' => 1, 'exact' => !empty($e['off_track_at']),
        ], array_values(array_filter($inc, fn($e) => $e['pct'] !== null)));
        return [
            'rows' => $rows, 'points' => $points, 'exact' => $exact, 'missed' => $missed,
            'humans' => count(array_filter($d['drivers'], fn($x) => !$x['ai'])), 'events' => $events,
        ];
    }

    /** Session clock as h:mm:ss / m:ss. */
    public static function clock(float $t): string
    {
        $t = (int) floor($t);
        return $t >= 3600 ? sprintf('%d:%02d:%02d', intdiv($t, 3600), intdiv($t % 3600, 60), $t % 60) : sprintf('%d:%02d', intdiv($t, 60), $t % 60);
    }

    /** The track's map, from the same cache the season pages use (fetched once if this track is new). */
    public static function map(int $trackId): ?array
    {
        if ($trackId <= 0) return null;
        try {
            $store = Store::open();
            $overrides = json_decode($store->setting('track_overrides', '{}'), true) ?: [];
            return TrackMaps::load([$trackId], (string) Config::get('track_map_source'), [$store, 'trackMap'], [$store, 'saveTrackMap'], $overrides, fn() => null)[$trackId] ?? null;
        } catch (\Throwable $e) {
            App::log($e);
            return null;
        }
    }

    /** Driver colours, borrowed from the seasons so a driver looks the same everywhere. */
    public static function slots(): array
    {
        $slots = [];
        foreach (Data::index()['seasons'] ?? [] as $s) {
            foreach (Data::season($s['slug'])['drivers'] ?? [] as $x) $slots[$x['name']] ??= $x['color'];
        }
        return $slots;
    }
}
