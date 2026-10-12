<?php
/* The compiler: stored race exports + harvested replays + track maps → the JSON the pages read.
   Runs once per upload (not per page view), so pages only render precomputed numbers. */
declare(strict_types=1);

namespace PitWall;

use PitWall\Stats\Hotspots;
use PitWall\Stats\Race;
use PitWall\Stats\Schedule;
use PitWall\Stats\Season;

final class Compiler
{
    /**
     * Pure build, no I/O.
     * @param array<string, array> $seasons   season name → processed race records
     * @param array<int, array>    $harvests  subsession → harvest file
     * @param callable $maps                  fn(int[] $trackIds): array<int, ?array>
     * @param array<string, array> $schedules season name → normalized schedule entries
     * @return array{index: array, seasons: array<string, array>, tracks: array}
     */
    public static function build(array $seasons, array $harvests, callable $maps, string $generated, string $mapSource, array $errors = [], array $schedules = []): array
    {
        // a scheduled track with no id borrows it from any race the site already holds there
        $known = Schedule::knownTracks(array_merge(...array_values($seasons)));

        // colour slots are assigned once across every season so a driver keeps their colour
        $humans = [];
        foreach ($seasons as $races) foreach ($races as $r) foreach ($r['entries'] as $e) if (!$e['ai']) $humans[] = $e['name'];
        $palette = array_flip(Num::uniqueSorted($humans));
        $colorOf = fn(string $d) => ($palette[$d] ?? 0) % 8;

        $index = [];
        $built = [];
        ksort($seasons, SORT_STRING);
        foreach ($seasons as $name => $races) {
            if (!$races) continue;
            $name = (string) $name;
            $incidents = [];
            foreach ($races as $r) {
                $h = $harvests[$r['subsession']] ?? null;
                if ($h) {
                    $incidents[$r['subsession']] = [
                        'events' => self::matchIncidents($r, $h), 'length_m' => $h['track']['length_m'] ?? null,
                        'splits' => $h['splits'] ?? null, 'raw' => $h['incidents'], 'order' => self::lapOrder($h),
                    ];
                }
            }
            $season = Season::build($name, $races, $colorOf, $incidents, $generated, $schedules[$name] ?? [], $known);
            $built[$season['slug']] = $season;
            $R = $season['rounds'];
            $index[] = [
                'name' => $name, 'slug' => $season['slug'], 'league' => $season['league'], 'rounds' => count($R),
                'upcoming' => count($season['calendar']) - count($R),
                'first' => $R[0]['date'], 'last' => $R[count($R) - 1]['date'],
                'leader' => $season['standings'][0]['driver'] ?? null, 'file' => "season-{$season['slug']}.json",
            ];
        }
        usort($index, fn($a, $b) => strcmp($b['last'], $a['last']));

        // ---- tracks: geometry + every season's hotspots at each track
        $ids = [];
        foreach ($built as $s) foreach ($s['rounds'] as $rd) $ids[$rd['track_id']] = true;
        // tracks still to come get a map too
        $ahead = [];
        foreach ($built as $s) foreach ($s['calendar'] as $c) {
            if ($c['status'] === 'upcoming' && $c['track_id'] !== null && !isset($ids[$c['track_id']])) $ahead[$c['track_id']] ??= $c;
        }
        $ids = array_merge(array_keys($ids), array_keys($ahead));
        $geo = $ids ? $maps($ids) : [];
        $tracks = [];
        foreach ($ids as $id) {
            if (isset($ahead[$id])) {
                $tracks[(string) $id] = [
                    'id' => $id, 'name' => $ahead[$id]['track'], 'short' => $ahead[$id]['track_short'], 'map' => $geo[$id] ?? null,
                    'length_m' => null, 'seasons' => [], 'hotspots_all' => null,
                ];
                continue;
            }
            $pages = [];
            foreach ($built as $s) foreach ($s['track_pages'] as $p) if ($p['track_id'] === $id) $pages[] = [$s, $p];
            $events = [];
            $lengthM = null;
            foreach ($pages as [$s, $p]) {
                foreach ($p['hotspots']['events'] ?? [] as $e) $events[] = $e + ['season' => $s['slug']];
                $lengthM ??= ($p['hotspots']['length_m'] ?? null) ?: null;
            }
            $tracks[(string) $id] = [
                'id' => $id,
                'name' => $pages[0][1]['track'],
                'short' => $pages[0][1]['track_short'],
                'map' => $geo[$id] ?? null,
                'length_m' => $lengthM,
                'seasons' => array_map(fn($sp) => ['slug' => $sp[0]['slug'], 'name' => $sp[0]['season'], 'rounds' => $sp[1]['rounds']], $pages),
                'hotspots_all' => $events ? Hotspots::set($events, $lengthM) : null,
            ];
        }

        return [
            'index' => ['generated' => $generated, 'seasons' => $index, 'errors' => $errors],
            'seasons' => $built,
            'tracks' => ['generated' => $generated, 'source' => $mapSource, 'tracks' => $tracks],
        ];
    }

    /** Replay incidents for one race, matched to its drivers by customer ID (names can differ). */
    private static function matchIncidents(array $race, array $h): array
    {
        $byId = [];
        $byName = [];
        foreach ($race['entries'] as $e) { $byId[$e['cust_id']] = $e; $byName[$e['name']] = $e; }
        return array_map(function ($i) use ($byId, $byName) {
            $e = $byId[$i['cust_id']] ?? $byName[$i['name']] ?? null;
            return [
                'pct' => $i['pct'], 'driver' => $e['name'] ?? $i['name'], 'team' => $e ? !$e['ai'] : false, 'ai' => $e ? $e['ai'] : $i['ai'],
                'lap' => $i['lap'], 't' => $i['t'], 'kind' => $i['kind'] ?? ($i['off_track'] ? 'off' : 'spin'), 'off' => $i['off_track'],
            ];
        }, $h['incidents']);
    }

    /** The replay's lap chart with each car as its customer ID, the way the race export knows it. */
    private static function lapOrder(array $h): ?array
    {
        if (!is_array($h['lap_order'] ?? null)) return null;
        $ids = array_column($h['drivers'] ?? [], 'cust_id', 'car_idx');
        return array_map(
            fn($lap) => array_values(array_filter(array_map(fn($car) => $ids[$car] ?? null, (array) $lap), fn($id) => $id !== null)),
            $h['lap_order'],
        );
    }

    // ---------------------------------------------------------------- with storage
    /** Rebuild everything from the database and publish it to the cache folder. */
    public static function run(Store $store, ?callable $log = null): array
    {
        $log ??= fn(string $s) => null;
        $t0 = hrtime(true);
        $lock = fopen(Paths::storage('compile.lock'), 'c');
        flock($lock, LOCK_EX);
        try {
            $errors = [];
            $seasons = [];
            foreach ($store->seasons() as $s) $seasons[$s['name']] = [];
            foreach ($store->racesForCompile() as $row) {
                try {
                    $race = $row['process_version'] === Race::VERSION ? json_decode($row['processed'], true) : null;
                    if (!$race) {
                        $race = Race::process(Store::decodeRaw($store->raceRaw((int) $row['subsession'])), $row['filename']);
                        $store->updateProcessed((int) $row['subsession'], $race);
                    }
                    $seasons[$row['season']][] = $race;
                } catch (\Throwable $e) {
                    $errors[] = "{$row['filename']}: {$e->getMessage()}";
                }
            }
            $harvests = [];
            foreach ($store->harvestsForCompile() as $row) {
                try {
                    $harvests[(int) $row['subsession']] = Store::decodeRaw($row['raw']);
                } catch (\Throwable $e) {
                    $errors[] = "incidents-{$row['subsession']}.json: {$e->getMessage()}";
                }
            }
            $schedules = [];
            foreach ($store->schedulesForCompile() as $row) $schedules[$row['season']] = json_decode($row['rounds'], true) ?: [];
            $source = Config::get('track_map_source');
            $overrides = json_decode($store->setting('track_overrides', '{}'), true) ?: [];
            $maps = fn(array $ids) => TrackMaps::load($ids, $source, [$store, 'trackMap'], [$store, 'saveTrackMap'], $overrides, $log);
            $out = self::build($seasons, $harvests, $maps, gmdate('Y-m-d\TH:i:s\Z'), $source, $errors, $schedules);

            // stamped with the code that built it, so a site update republishes by itself (Site::republish)
            $out['index']['build'] = PITWALL_VERSION;

            $dir = Paths::cache();
            foreach ($out['seasons'] as $slug => $season) Files::writeJson("{$dir}/season-{$slug}.json", $season);
            Files::writeJson("{$dir}/tracks.json", $out['tracks']);
            // the index goes last: it's what pages and pollers watch for a new version
            Files::writeJson("{$dir}/seasons.json", $out['index']);
            $keep = array_map(fn($s) => $s['file'], $out['index']['seasons']);
            foreach (glob("{$dir}/season-*.json") ?: [] as $f) if (!in_array(basename($f), $keep, true)) @unlink($f);
            $races = array_sum(array_map('count', $seasons));
            return [
                'seasons' => count($out['index']['seasons']), 'races' => $races, 'harvests' => count($harvests),
                'errors' => $errors, 'ms' => (int) round((hrtime(true) - $t0) / 1e6), 'generated' => $out['index']['generated'],
            ];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
