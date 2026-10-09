<?php
/* Races of one season → every season stat the site shows.
   Team stats cover human drivers only; AI times serve as a pace benchmark. */
declare(strict_types=1);

namespace PitWall\Stats;

use PitWall\Num;

final class Season
{
    /**
     * @param array $races      processed race records (Race::process)
     * @param callable $colorOf driver name → colour slot
     * @param array $incidents  subsession → ['events' => [...], 'length_m' => ?, 'splits' => ?, 'raw' => [...]]
     */
    public static function build(string $name, array $races, callable $colorOf, array $incidents, string $generated): array
    {
        usort($races, fn($a, $b) => strcmp($a['date'], $b['date']));
        $teamNames = [];
        foreach ($races as $r) foreach ($r['entries'] as $e) if (!$e['ai']) $teamNames[] = $e['name'];
        $teamNames = Num::uniqueSorted($teamNames);

        $rounds = [];
        $results = [];
        foreach ($races as $idx => $r) {
            $i = $idx + 1;
            $ents = $r['entries'];
            $team = array_values(array_filter($ents, fn($e) => !$e['ai']));
            $withBest = fn(array $xs) => array_values(array_filter($xs, fn($e) => (bool) $e['best']));
            $fieldBest = Num::minTruthy(array_column($ents, 'best'));
            $teamBest = Num::minTruthy(array_column($team, 'best'));
            $aiBest = Num::minTruthy(array_column(array_filter($ents, fn($e) => $e['ai']), 'best'));
            $fl = Num::minBy($withBest($ents), fn($e) => $e['best']);
            $tfl = Num::minBy($withBest($team), fn($e) => $e['best']);
            $pole = Num::minBy($ents, fn($e) => $e['start']);
            $teamSortedBest = $withBest($team);
            usort($teamSortedBest, fn($a, $b) => $a['best'] <=> $b['best']);

            // fastest lap per car across the whole field (AI included) at this track
            $byCar = [];
            foreach ($ents as $e) if ($e['best']) $byCar[$e['car']][] = $e;
            $carPace = [];
            foreach ($byCar as $car => $es) {
                $b = Num::minBy($es, fn($e) => $e['best']);
                $carPace[] = [
                    'car' => (string) $car, 'best' => $b['best'], 'by' => $b['name'], 'ai' => $b['ai'],
                    'entries' => count($es), 'avg_best' => Num::rnd(Num::mean(array_column($es, 'best'))),
                    'gap_pct' => $fieldBest ? Num::rnd(($b['best'] / $fieldBest - 1) * 100) : null,
                ];
            }
            usort($carPace, fn($a, $b) => $a['best'] <=> $b['best']);

            $sorted = $team;
            usort($sorted, fn($a, $b) => $a['finish'] <=> $b['finish']);
            $teamFinish = [];
            foreach ($sorted as $k => $e) $teamFinish[$e['cust_id']] = $k + 1;
            $q = array_values(array_filter($team, fn($e) => (bool) $e['qual']));
            usort($q, fn($a, $b) => $a['qual'] <=> $b['qual']);
            $teamQual = [];
            foreach ($q as $k => $e) $teamQual[$e['cust_id']] = $k + 1;

            $teamInc = Num::sum(array_column($team, 'inc'));
            $teamLaps = Num::sum(array_column($team, 'laps'));
            $rounds[] = [
                'round' => $i,
                'date' => $r['date'],
                'track' => $r['track'],
                'track_short' => $r['track_short'],
                'track_id' => $r['track_id'],
                'subsession' => $r['subsession'],
                'sof' => $r['sof'],
                'laps' => $r['race_laps'],
                'starters' => count($ents),
                'humans' => count($team),
                'ai' => count($ents) - count($team),
                'winner' => $ents[0]['name'], 'winner_ai' => $ents[0]['ai'], 'winner_car' => $ents[0]['car'],
                'win_margin' => count($ents) > 1 ? $ents[1]['interval'] : null,
                'pole' => $pole['name'], 'pole_ai' => $pole['ai'],
                'fastest' => $fl ? ['name' => $fl['name'], 'ai' => $fl['ai'], 'time' => $fl['best'], 'car' => $fl['car']] : null,
                'team_fastest' => $tfl ? [
                    'name' => $tfl['name'], 'time' => $tfl['best'], 'car' => $tfl['car'],
                    'margin' => count($teamSortedBest) > 1 ? Num::rnd($teamSortedBest[1]['best'] - $tfl['best']) : null,
                ] : null,
                'field_best' => $fieldBest, 'team_best' => $teamBest, 'ai_best' => $aiBest,
                'team_inc' => $teamInc,
                'team_inc_per_lap' => $teamLaps ? Num::rnd($teamInc / $teamLaps, 4) : null,
                'field_inc' => Num::sum(array_column($ents, 'inc')),
                'car_pace' => $carPace,
                'cars_used' => Num::uniqueSorted(array_column($team, 'car')),
                'cautions' => $r['cautions'],
                'harvested' => isset($incidents[$r['subsession']]),
            ];

            foreach ($team as $e) {
                $aiBeaten = count(array_filter($ents, fn($o) => $o['ai'] && $o['finish'] > $e['finish']));
                $results[] = [
                    'round' => $i, 'driver' => $e['name'], 'car' => $e['car'],
                    'start' => $e['start'], 'finish' => $e['finish'],
                    'team_finish' => $teamFinish[$e['cust_id']],
                    'team_qual' => $teamQual[$e['cust_id']] ?? null,
                    'gained' => $e['start'] - $e['finish'],
                    'laps' => $e['laps'], 'laps_led' => $e['laps_led'],
                    'best' => $e['best'], 'best_lap_num' => $e['best_lap_num'], 'avg' => $e['avg'],
                    'qual' => $e['qual'], 'qual_rank' => $e['qual_rank'], 'prac' => $e['prac'],
                    'inc' => $e['inc'],
                    'inc_per_lap' => $e['laps'] ? Num::rnd($e['inc'] / $e['laps'], 4) : null,
                    'points' => $e['points'], 'status' => $e['status'],
                    'gap_to_winner' => $e['interval'],
                    'gap_field_pct' => $e['best'] && $fieldBest ? Num::rnd(($e['best'] / $fieldBest - 1) * 100) : null,
                    'gap_team_pct' => $e['best'] && $teamBest ? Num::rnd(($e['best'] / $teamBest - 1) * 100) : null,
                    'gap_team_s' => $e['best'] && $teamBest ? Num::rnd($e['best'] - $teamBest) : null,
                    'consistency_pct' => $e['avg'] && $e['best'] ? Num::rnd(($e['avg'] / $e['best'] - 1) * 100) : null,
                    'ai_beaten' => $aiBeaten,
                    'finished' => $e['status'] === 'Running',
                ];
            }
        }

        // ---- standings
        $per = [];
        foreach ($results as $x) $per[$x['driver']][] = $x;
        $rows = [];
        foreach ($per as $d => $xs) {
            $d = (string) $d;
            $laps = Num::sum(array_column($xs, 'laps'));
            $inc = Num::sum(array_column($xs, 'inc'));
            $col = fn(string $k) => array_column($xs, $k);
            $rows[] = [
                'driver' => $d, 'color' => $colorOf($d),
                'points' => Num::sum($col('points')),
                'races' => count($xs),
                'wins' => count(array_filter($xs, fn($x) => $x['finish'] === 1)),
                'podiums' => count(array_filter($xs, fn($x) => $x['finish'] <= 3)),
                'team_wins' => count(array_filter($xs, fn($x) => $x['team_finish'] === 1)),
                'poles' => count(array_filter($xs, fn($x) => $x['start'] === 1)),
                'team_fastest_laps' => count(array_filter($rounds, fn($r) => $r['team_fastest'] && $r['team_fastest']['name'] === $d)),
                'best_finish' => min($col('finish')),
                'avg_finish' => Num::rnd(Num::mean($col('finish')), 2),
                'avg_team_finish' => Num::rnd(Num::mean($col('team_finish')), 2),
                'avg_start' => Num::rnd(Num::mean($col('start')), 2),
                'avg_gained' => Num::rnd(Num::mean($col('gained')), 2),
                'laps' => $laps, 'laps_led' => Num::sum($col('laps_led')),
                'inc' => $inc, 'inc_per_race' => Num::rnd($inc / count($xs), 2),
                'inc_per_lap' => $laps ? Num::rnd($inc / $laps, 4) : null,
                'avg_gap_team_pct' => Num::rnd(Num::mean($col('gap_team_pct'))),
                'avg_gap_field_pct' => Num::rnd(Num::mean($col('gap_field_pct'))),
                'avg_consistency_pct' => Num::rnd(Num::mean($col('consistency_pct'))),
                'ai_beaten' => Num::sum($col('ai_beaten')),
                'dnfs' => count(array_filter($xs, fn($x) => !$x['finished'])),
                'cars' => Num::mostCommon($col('car')),
                'finish_std' => count($xs) > 1 ? Num::rnd(Num::pstdev($col('finish')), 2) : null,
            ];
        }
        usort($rows, fn($a, $b) => Num::cmpTuple([-$a['points'], -$a['wins'], $a['avg_finish'] ?? 0], [-$b['points'], -$b['wins'], $b['avg_finish'] ?? 0]));
        $leaderPts = $rows[0]['points'] ?? 0;
        $standings = [];
        foreach ($rows as $k => $s) $standings[] = $s + ['pos' => $k + 1, 'gap_to_leader' => $leaderPts - $s['points']];

        // ---- points progression (cumulative)
        $progression = [];
        foreach ($teamNames as $d) {
            $tot = 0;
            $progression[$d] = [];
            foreach ($rounds as $rd) {
                foreach ($per[$d] ?? [] as $x) if ($x['round'] === $rd['round']) { $tot += $x['points']; break; }
                $progression[$d][] = $tot;
            }
        }

        // ---- head to head (race finishes and qualifying)
        $grid = [];
        foreach ($teamNames as $a) foreach ($teamNames as $b) $grid[$a][$b] = 0;
        $h2hRace = $grid;
        $h2hQual = $grid;
        foreach ($rounds as $rd) {
            $xs = array_values(array_filter($results, fn($x) => $x['round'] === $rd['round']));
            foreach ($xs as $ia => $a) foreach ($xs as $ib => $b) {
                if ($ia === $ib) continue;
                if ($a['finish'] < $b['finish']) $h2hRace[$a['driver']][$b['driver']]++;
                if ($a['qual'] && (!$b['qual'] || $a['qual'] < $b['qual'])) $h2hQual[$a['driver']][$b['driver']]++;
            }
        }

        // ---- tracks: difficulty for the group
        $tracks = [];
        foreach ($rounds as $rd) {
            $xs = array_values(array_filter($results, fn($x) => $x['round'] === $rd['round']));
            $gaps = array_values(array_filter(array_column($xs, 'gap_team_pct'), fn($g) => $g !== null));
            $tracks[] = [
                'round' => $rd['round'], 'track' => $rd['track'], 'track_short' => $rd['track_short'],
                'inc_per_lap' => $rd['team_inc_per_lap'], 'inc' => $rd['team_inc'],
                'avg_inc' => $xs ? Num::rnd($rd['team_inc'] / count($xs), 2) : null,
                'pace_spread_pct' => count($gaps) > 1 ? Num::rnd(Num::pstdev($gaps), 3) : null,
                'avg_gained' => Num::rnd(Num::mean(array_column($xs, 'gained')), 2),
                'avg_consistency_pct' => Num::rnd(Num::mean(array_column($xs, 'consistency_pct'))),
                'team_vs_ai_pct' => $rd['team_best'] && $rd['ai_best'] ? Num::rnd(($rd['team_best'] / $rd['ai_best'] - 1) * 100) : null,
                'difficulty' => 0, 'difficulty_rank' => 0,
            ];
        }
        // difficulty: incidents per lap (50%) + consistency loss (30%) + positions lost (20%), as z-scores
        $z = function (array $vals): array {
            $v = array_values(array_filter($vals, fn($x) => $x !== null));
            if (count($v) < 2) return array_map(fn() => 0, $vals);
            $m = Num::sum($v) / count($v);
            $sd = Num::pstdev($v) ?: 1;
            return array_map(fn($x) => $x !== null ? ($x - $m) / $sd : 0, $vals);
        };
        $zi = $z(array_column($tracks, 'inc_per_lap'));
        $zc = $z(array_column($tracks, 'avg_consistency_pct'));
        $zg = $z(array_map(fn($t) => -($t['avg_gained'] ?: 0), $tracks));
        foreach ($tracks as $k => &$t) $t['difficulty'] = Num::rnd($zi[$k] * 0.5 + $zc[$k] * 0.3 + $zg[$k] * 0.2, 3);
        unset($t);
        $order = array_keys($tracks);
        usort($order, fn($a, $b) => $tracks[$b]['difficulty'] <=> $tracks[$a]['difficulty']);
        foreach ($order as $k => $ti) $tracks[$ti]['difficulty_rank'] = $k + 1;

        $hardestByDriver = [];
        foreach ($teamNames as $d) {
            $xs = $per[$d] ?? [];
            if (!$xs) continue;
            $worstInc = Num::maxBy($xs, fn($x) => $x['inc_per_lap'] ?: 0);
            $worstPace = Num::maxBy($xs, fn($x) => $x['gap_team_pct'] ?? -1);
            $best = $xs[0];
            foreach (array_slice($xs, 1) as $x) {
                if (Num::cmpTuple([$x['team_finish'], $x['inc_per_lap'] ?: 0], [$best['team_finish'], $best['inc_per_lap'] ?: 0]) < 0) $best = $x;
            }
            $hardestByDriver[$d] = [
                'most_incidents' => ['round' => $worstInc['round'], 'inc' => $worstInc['inc'], 'inc_per_lap' => $worstInc['inc_per_lap']],
                'slowest_vs_team' => ['round' => $worstPace['round'], 'gap_pct' => $worstPace['gap_team_pct']],
                'best_track' => ['round' => $best['round'], 'team_finish' => $best['team_finish'], 'finish' => $best['finish']],
            ];
        }

        // ---- cars
        $carRows = [];
        foreach ($results as $x) $carRows[$x['car']][] = $x;
        $cars = [];
        foreach ($carRows as $car => $xs) {
            $car = (string) $car;
            $cars[] = [
                'car' => $car, 'starts' => count($xs), 'drivers' => Num::uniqueSorted(array_column($xs, 'driver')),
                'wins' => count(array_filter($xs, fn($x) => $x['finish'] === 1)),
                'avg_finish' => Num::rnd(Num::mean(array_column($xs, 'finish')), 2),
                'avg_gap_team_pct' => Num::rnd(Num::mean(array_column($xs, 'gap_team_pct'))),
                'avg_inc_per_lap' => Num::rnd(Num::mean(array_column($xs, 'inc_per_lap')), 4),
                'fastest_at' => array_values(array_map(fn($rd) => $rd['round'],
                    array_filter($rounds, fn($rd) => $rd['car_pace'] && $rd['car_pace'][0]['car'] === $car))),
                'rounds_in_field' => Num::sum(array_map(fn($rd) => count(array_filter($rd['car_pace'], fn($cp) => $cp['car'] === $car)), $rounds)),
            ];
        }
        usort($cars, fn($a, $b) => Num::cmpTuple([-$a['starts'], $a['avg_finish'] ?: 99], [-$b['starts'], $b['avg_finish'] ?: 99]));

        $awards = Awards::compute($results, $rounds, $standings, $per);
        $trackPages = self::trackPages($races, $rounds, $results, $standings, $tracks, $incidents);

        $league = $name;
        foreach ($races as $r) if ($r['league']) { $league = $r['league']; break; }
        return [
            'season' => $name,
            'slug' => Num::slug($name),
            'league' => $league,
            'generated' => $generated,
            'drivers' => array_map(fn($d) => ['name' => $d, 'color' => $colorOf($d)], $teamNames),
            'rounds' => $rounds, 'results' => $results, 'standings' => $standings, 'progression' => $progression,
            'h2h_race' => $h2hRace, 'h2h_qual' => $h2hQual,
            'tracks' => $tracks, 'hardest_by_driver' => $hardestByDriver, 'cars' => $cars, 'awards' => $awards,
            'track_pages' => $trackPages,
        ];
    }

    // ---------------------------------------------------------------- per-track pages
    private static function trackPages(array $races, array $rounds, array $results, array $standings, array $tracks, array $incidents): array
    {
        $raceOf = [];
        foreach ($rounds as $k => $rd) $raceOf[$rd['round']] = $races[$k];
        $seasonRate = array_column($standings, 'inc_per_lap', 'driver');
        $totLaps = Num::sum(array_column($results, 'laps'));
        $totInc = Num::sum(array_column($results, 'inc'));
        $ids = array_values(array_unique(array_column($rounds, 'track_id')));

        $pages = [];
        foreach ($ids as $id) {
            $rs = array_values(array_filter($rounds, fn($r) => $r['track_id'] === $id));
            $nums = array_column($rs, 'round');
            $xs = array_values(array_filter($results, fn($x) => in_array($x['round'], $nums, true)));
            $allEntries = array_merge(...array_map(fn($r) => $raceOf[$r['round']]['entries'], $rs));
            $laps = Num::sum(array_column($xs, 'laps'));
            $teamInc = Num::sum(array_column($xs, 'inc'));

            // incident markers from harvested replays, tagged with their round
            $events = [];
            $byRound = [];
            $splits = [];
            $lengthM = null;
            foreach ($rs as $r) {
                $h = $incidents[$r['subsession']] ?? null;
                if (!$h) continue;
                $lengthM ??= $h['length_m'];
                $evs = array_map(fn($e) => $e + ['round' => $r['round']], $h['events']);
                array_push($events, ...$evs);
                $byRound[(string) $r['round']] = Hotspots::summarize($evs, $h['length_m']);
                $sp = !empty($h['splits']) ? Splits::round($r['round'], $raceOf[$r['round']], $h['splits'], $h['raw']) : null;
                if ($sp) $splits[] = $sp;
            }
            $harvested = array_values(array_filter($rs, fn($r) => isset($incidents[$r['subsession']])));

            $drivers = [];
            foreach ($xs as $x) {
                $sr = $seasonRate[$x['driver']] ?? null;
                $sub = null;
                foreach ($rs as $r) if ($r['round'] === $x['round']) { $sub = $r['subsession']; break; }
                $isHarvested = isset($incidents[$sub]);
                $drivers[] = [
                    'driver' => $x['driver'], 'round' => $x['round'], 'car' => $x['car'],
                    'start' => $x['start'], 'finish' => $x['finish'], 'laps' => $x['laps'],
                    'inc' => $x['inc'],
                    'inc_per_10' => $x['inc_per_lap'] !== null ? Num::rnd($x['inc_per_lap'] * 10, 2) : null,
                    'season_inc_per_10' => $sr !== null ? Num::rnd($sr * 10, 2) : null,
                    'markers' => $isHarvested ? count(array_filter($events, fn($e) => $e['round'] === $x['round'] && $e['driver'] === $x['driver'])) : null,
                    'points' => $x['points'], 'status' => $x['status'],
                ];
            }
            usort($drivers, fn($a, $b) => $a['round'] <=> $b['round'] ?: $a['finish'] <=> $b['finish']);

            // cleanest / roughest across this track's rounds
            $agg = [];
            foreach ($xs as $x) {
                $agg[$x['driver']] ??= ['inc' => 0, 'laps' => 0];
                $agg[$x['driver']]['inc'] += $x['inc'];
                $agg[$x['driver']]['laps'] += $x['laps'];
            }
            $rates = [];
            foreach ($agg as $driver => $a) {
                if ($a['laps'] > 0) $rates[] = ['driver' => (string) $driver, 'inc_per_10' => Num::rnd(($a['inc'] / $a['laps']) * 10, 2)];
            }
            $ranks = array_column(array_filter($tracks, fn($t) => in_array($t['round'], $nums, true)), 'difficulty_rank');

            $pages[] = [
                'track_id' => $id,
                'track' => $rs[0]['track'],
                'track_short' => $rs[0]['track_short'],
                'rounds' => $nums,
                'laps' => $laps,
                'team_inc' => $teamInc,
                'field_inc' => Num::sum(array_column($allEntries, 'inc')),
                'ai_inc' => Num::sum(array_column(array_filter($allEntries, fn($e) => $e['ai']), 'inc')),
                'team_inc_per_10' => $laps ? Num::rnd(($teamInc / $laps) * 10, 2) : null,
                'season_team_inc_per_10' => $totLaps ? Num::rnd(($totInc / $totLaps) * 10, 2) : null,
                'difficulty_rank' => $ranks ? min($ranks) : null,
                'cleanest' => Num::minBy($rates, fn($r) => $r['inc_per_10']),
                'roughest' => Num::maxBy($rates, fn($r) => $r['inc_per_10']),
                'drivers' => $drivers,
                'harvested_rounds' => array_column($harvested, 'round'),
                'pending' => array_values(array_map(fn($r) => ['round' => $r['round'], 'subsession' => $r['subsession']],
                    array_filter($rs, fn($r) => !isset($incidents[$r['subsession']])))),
                'hotspots' => $harvested ? Hotspots::set($events, $lengthM) : null,
                'by_round' => $byRound,
                'splits' => $splits,
            ];
        }
        return $pages;
    }
}
