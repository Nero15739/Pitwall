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
        return preg_match('/^live-[a-z0-9]+(?:-[a-z0-9]+)*\z/', $id) && strlen($id) <= 100 ? self::dir() . "/{$id}.json" : null;
    }

    /**
     * A live log rebuilt field by field with the types the pages expect, or a UserError naming what's wrong.
     * Whoever holds an API key can upload one and the public /live pages show it, so nothing else gets
     * through: no unexpected fields, no strings where numbers go, no markup where a count is printed.
     */
    public static function clean(array $doc): array
    {
        if (($doc['method'] ?? null) !== 'live' || ($doc['version'] ?? null) !== 1) {
            throw new UserError('This endpoint takes a live log from the Pit Wall Logger ("method": "live", "version": 1).');
        }
        if (!is_array($doc['incidents'] ?? null) || !is_array($doc['drivers'] ?? null) || !array_is_list($doc['incidents']) || !array_is_list($doc['drivers'])) {
            throw new UserError('The live log has no drivers or incidents list.');
        }
        $bad = fn(string $why) => new UserError("Not a usable live log ({$why}).");
        $int = function (mixed $v, string $what, bool $null = false) use ($bad): ?int {
            if ($v === null && $null) return null;
            if (is_float($v) && is_finite($v) && floor($v) === $v && abs($v) < PHP_INT_MAX) $v = (int) $v;
            if (!is_int($v)) throw $bad("{$what} must be a whole number");
            return $v;
        };
        $num = function (mixed $v, string $what, bool $null = false) use ($bad): int|float|null {
            if ($v === null && $null) return null;
            if (!(is_int($v) || (is_float($v) && is_finite($v)))) throw $bad("{$what} must be a number");
            return $v;
        };
        $str = function (mixed $v, string $what, bool $null = false, int $max = 200) use ($bad): ?string {
            if ($v === null && $null) return null;
            if (is_int($v)) $v = (string) $v;
            if (!is_string($v) || !mb_check_encoding($v, 'UTF-8')) throw $bad("{$what} must be text");
            return mb_substr($v, 0, $max);
        };
        $bool = function (mixed $v, string $what) use ($bad): bool {
            if (!is_bool($v)) throw $bad("{$what} must be true or false");
            return $v;
        };
        $obj = fn(mixed $v, string $what): array => is_array($v) && !array_is_list($v) || $v === [] ? $v : throw $bad("{$what} must be an object");
        $date = function (mixed $v, string $what) use ($str, $bad): ?string {
            $v = $str($v, $what, true, 40);
            if ($v !== null && ($v === '' || strtotime($v) === false)) throw $bad("{$what} must be a date");
            return $v;
        };
        $place = fn(mixed $p, string $what): ?array => $p === null ? null : [
            't' => $num($obj($p, $what)['t'] ?? null, "{$what}.t"),
            'lap' => $int($p['lap'] ?? null, "{$what}.lap", true),
            'pct' => $num($p['pct'] ?? null, "{$what}.pct", true),
        ];
        if (count($doc['drivers']) > 256 || count($doc['incidents']) > 20000) throw $bad('too many drivers or incidents');

        $session = $obj($doc['session'] ?? null, 'session');
        $track = $obj($doc['track'] ?? null, 'track');
        $by = $obj($doc['recorded_by'] ?? [], 'recorded_by');
        $out = [
            'version' => 1,
            'method' => 'live',
            'subsession' => max(0, $int($doc['subsession'] ?? 0, 'subsession')),
            'session' => ['num' => $int($session['num'] ?? 0, 'session.num'), 'type' => $str($session['type'] ?? 'Session', 'session.type', false, 40)],
            'track' => [
                'id' => $int($track['id'] ?? 0, 'track.id'),
                'name' => $str($track['name'] ?? '', 'track.name'),
                'short' => $str($track['short'] ?? null, 'track.short', true),
                'config' => $str($track['config'] ?? null, 'track.config', true),
                'length_m' => $num($track['length_m'] ?? null, 'track.length_m', true),
            ],
            'recorded_by' => ['cust_id' => $int($by['cust_id'] ?? 0, 'recorded_by.cust_id'), 'name' => $str($by['name'] ?? '', 'recorded_by.name')],
            'view' => $str($doc['view'] ?? '', 'view', false, 40),
            'started' => $date($doc['started'] ?? null, 'started'),
            'ended' => $date($doc['ended'] ?? null, 'ended'),
            'finished' => $bool($doc['finished'] ?? false, 'finished'),
            'drivers' => [],
            'incidents' => [],
        ];
        foreach ($doc['drivers'] as $k => $x) {
            $x = $obj($x, "driver {$k}");
            $out['drivers'][] = [
                'car_idx' => $int($x['car_idx'] ?? null, "driver {$k} car_idx"),
                'cust_id' => $int($x['cust_id'] ?? 0, "driver {$k} cust_id"),
                'name' => $str($x['name'] ?? '', "driver {$k} name"),
                'number' => $str($x['number'] ?? null, "driver {$k} number", true, 8),
                'ai' => $bool($x['ai'] ?? false, "driver {$k} ai"),
                'incident_count' => $int($x['incident_count'] ?? null, "driver {$k} incident_count", true),
            ];
        }
        foreach ($doc['incidents'] as $k => $e) {
            $e = $obj($e, "incident {$k}");
            $out['incidents'][] = [
                't' => $num($e['t'] ?? null, "incident {$k} t"),
                'car_idx' => $int($e['car_idx'] ?? null, "incident {$k} car_idx"),
                'cust_id' => $int($e['cust_id'] ?? 0, "incident {$k} cust_id"),
                'name' => $str($e['name'] ?? '', "incident {$k} name"),
                'ai' => $bool($e['ai'] ?? false, "incident {$k} ai"),
                'from' => $int($e['from'] ?? null, "incident {$k} from"),
                'to' => $int($e['to'] ?? null, "incident {$k} to"),
                'delta' => $int($e['delta'] ?? null, "incident {$k} delta"),
                'kind' => $str($e['kind'] ?? null, "incident {$k} kind", false, 40),
                'lap' => $int($e['lap'] ?? null, "incident {$k} lap", true),
                'pct' => $num($e['pct'] ?? null, "incident {$k} pct", true),
                'seen_at' => $place($e['seen_at'] ?? null, "incident {$k} seen_at"),
                'off_track_at' => $place($e['off_track_at'] ?? null, "incident {$k} off_track_at"),
            ];
        }
        return $out;
    }

    /** A stored log, re-checked on the way out: one written before the checks above existed is skipped, not shown. */
    private static function read(string $path): ?array
    {
        $d = Files::readJson($path);
        if ($d === null) return null;
        try {
            return self::clean($d);
        } catch (UserError $e) {
            App::log(new \RuntimeException(basename($path) . ': ' . $e->getMessage()));
            return null;
        }
    }

    /** Check an uploaded live log and store it under a name built from what's in it. */
    public static function save(array $doc): array
    {
        $doc = self::clean($doc);
        $sub = $doc['subsession'];
        $type = preg_replace('/[^a-z0-9]+/', '', strtolower($doc['session']['type'])) ?: 'session';
        $when = strtotime((string) $doc['started']) ?: time();
        $id = 'live-' . ($sub > 0 ? $sub : gmdate('Ymd-Hi', $when)) . '-s' . max(0, $doc['session']['num']) . "-{$type}";
        $path = self::path($id) ?? throw new UserError('The live log’s session couldn’t be turned into a file name.');
        $old = self::read($path);
        $status = $old === null ? 'created' : ($old == $doc ? 'unchanged' : 'updated');
        if ($status !== 'unchanged') Files::writeJson($path, $doc);
        return ['type' => 'live', 'status' => $status, 'id' => $id, 'incidents' => count($doc['incidents'])];
    }

    /** A stored log as uploaded (Cutoffs::live gives it as shown). */
    public static function load(string $id): ?array
    {
        $p = self::path($id);
        return $p ? self::read($p) : null;
    }

    /** A subsession's race log as uploaded (its last race session, if there were heats). */
    public static function raceLog(int $sub): ?array
    {
        if ($sub <= 0) return null;
        $best = null;
        foreach (glob(self::dir() . "/live-{$sub}-s*-*race*.json") ?: [] as $f) {
            $d = self::read($f);
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
            $d = self::read($f);
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
