<?php
/* Season awards: the numbers behind the bragging rights. */
declare(strict_types=1);

namespace PitWall\Stats;

use PitWall\Num;

final class Awards
{
    public static function compute(array $results, array $rounds, array $standings, array $per): array
    {
        if (!$results) return [];
        $f = fn($x, int $d) => Num::fixed($x, $d);
        $signed = fn($x, int $d) => ($x >= 0 ? '+' : '') . Num::fixed($x, $d);
        $track = array_column($rounds, 'track_short', 'round');
        $A = [];
        $add = function (string $key, string $title, string $icon, string $driver, string $value, string $detail) use (&$A) {
            $A[] = compact('key', 'title', 'icon', 'driver', 'value', 'detail');
        };

        $x = Num::maxBy($results, fn($x) => $x['gained']);
        if ($x['gained'] > 0) {
            $add('charger', 'Hard Charger', 'rocket', $x['driver'], "+{$x['gained']}",
                "places gained in one race: P{$x['start']} → P{$x['finish']} at {$track[$x['round']]}");
        }

        $x = Num::maxBy($results, fn($x) => $x['inc']);
        $add('wrecking', 'Wrecking Ball', 'bolt', $x['driver'], "{$x['inc']}x", "incidents in a single race at {$track[$x['round']]}");

        $half = max(1, intdiv(count($rounds), 2));
        $eligible = array_filter($standings, fn($s) => $s['inc_per_lap'] !== null && $s['races'] >= $half);
        if ($eligible) {
            $s = Num::minBy($eligible, fn($s) => $s['inc_per_lap']);
            $add('clean', 'Clean Machine', 'shield', $s['driver'], $f($s['inc_per_lap'] * 10, 1), 'incidents per 10 laps, the lowest rate on the team');
        }

        $cons = array_filter($standings, fn($s) => $s['avg_consistency_pct'] !== null);
        if ($cons) {
            $s = Num::minBy($cons, fn($s) => $s['avg_consistency_pct']);
            $add('metronome', 'Metronome', 'metronome', $s['driver'], $f($s['avg_consistency_pct'], 2) . '%',
                'average lap within this much of their best lap, the most consistent pace');
        }

        $key = fn($s) => [$s['team_fastest_laps'], -($s['avg_gap_team_pct'] ?: 99)];
        $s = $standings[0];
        foreach (array_slice($standings, 1) as $c) if (Num::cmpTuple($key($c), $key($s)) > 0) $s = $c;
        if ($s['team_fastest_laps']) {
            $add('speed', 'Speed Demon', 'stopwatch', $s['driver'], (string) $s['team_fastest_laps'], 'team fastest laps from ' . count($rounds) . ' rounds');
        }

        $qualAvg = fn(string $d) => Num::mean(array_map(fn($x) => $x['team_qual'], array_filter($per[$d] ?? [], fn($x) => (bool) $x['team_qual'])));
        $qa = Num::minBy($standings, fn($s) => $qualAvg($s['driver']) ?: 99);
        $qv = $qualAvg($qa['driver']);
        if ($qv !== null) $add('quali', 'Quali Ace', 'flag', $qa['driver'], $f($qv, 1), 'average qualifying position within the team');

        $s = Num::maxBy($standings, fn($s) => $s['avg_gained'] ?: -99);
        if ($s['avg_gained'] !== null) $add('racecraft', 'Sunday Specialist', 'trend', $s['driver'], $signed($s['avg_gained'], 1), 'average places gained from grid to flag');

        $s = Num::maxBy($standings, fn($s) => $s['laps_led']);
        if ($s['laps_led']) $add('leader', 'Front Runner', 'crown', $s['driver'], (string) $s['laps_led'], 'laps led this season');

        $s = Num::maxBy($standings, fn($s) => $s['ai_beaten']);
        $add('ai', 'AI Slayer', 'robot', $s['driver'], (string) $s['ai_beaten'], 'AI cars finished ahead of, all season');

        $s = Num::maxBy($standings, fn($s) => count($s['cars']));
        if (count($s['cars']) > 1) {
            $add('hopper', 'Garage Tourist', 'car', $s['driver'], (string) count($s['cars']),
                'different cars driven: ' . implode(', ', array_keys($s['cars'])));
        }

        $loyal = array_filter($standings, fn($s) => count($s['cars']) === 1 && $s['races'] > 1);
        if ($loyal) {
            $l = Num::maxBy($loyal, fn($s) => $s['races']);
            $add('loyal', 'Brand Loyal', 'heart', $l['driver'], "{$l['races']}/{$l['races']}", 'races in the ' . array_key_first($l['cars']));
        }

        $stds = array_filter($standings, fn($s) => $s['finish_std'] !== null);
        if ($stds) {
            $st = Num::minBy($stds, fn($s) => $s['finish_std']);
            $add('steady', 'Mr Reliable', 'target', $st['driver'], '±' . $f($st['finish_std'], 1),
                'places of variation in finishing position, the steadiest results');
        }

        // closest team battle at the flag
        $best = null;
        foreach ($rounds as $rd) {
            $xs = array_values(array_filter($results, fn($x) => $x['round'] === $rd['round'] && $x['gap_to_winner'] !== null));
            usort($xs, fn($a, $b) => $a['finish'] <=> $b['finish']);
            for ($k = 0; $k + 1 < count($xs); $k++) {
                $gap = $xs[$k + 1]['gap_to_winner'] - $xs[$k]['gap_to_winner'];
                if ($best === null || $gap < $best['gap']) $best = ['gap' => $gap, 'a' => $xs[$k], 'b' => $xs[$k + 1], 'rd' => $rd];
            }
        }
        if ($best) {
            $add('photo', 'Photo Finish', 'camera', "{$best['a']['driver']} vs {$best['b']['driver']}", $f($best['gap'], 3) . 's',
                "between team-mates at the line, {$best['rd']['track_short']}");
        }

        // biggest winning margin over the next car
        $margins = array_filter($rounds, fn($rd) => $rd['win_margin'] && !$rd['winner_ai']);
        if ($margins) {
            $rd = Num::maxBy($margins, fn($rd) => $rd['win_margin']);
            $add('dominant', 'Dominant Win', 'trophy', $rd['winner'], $f($rd['win_margin'], 1) . 's', "winning margin at {$rd['track_short']}");
        }

        // fast but unrewarded: team fastest lap without the team win
        foreach ($rounds as $rd) {
            $tf = $rd['team_fastest'];
            if (!$tf) continue;
            foreach ($results as $r) {
                if ($r['round'] !== $rd['round'] || $r['driver'] !== $tf['name']) continue;
                if ($r['team_finish'] > 1) {
                    $add('unlucky', 'Fastest, Not First', 'clock', $r['driver'], "P{$r['finish']}",
                        "set the team's fastest lap at {$rd['track_short']} but finished P{$r['finish']}");
                    break 2;
                }
                break;
            }
        }

        // practice hero: most rounds topping team practice
        $pracTop = [];
        foreach ($rounds as $rd) {
            $xs = array_values(array_filter($results, fn($x) => $x['round'] === $rd['round'] && $x['prac']));
            if (count($xs) < 2) continue;
            $pracTop[] = Num::minBy($xs, fn($x) => $x['prac'])['driver'];
        }
        if ($pracTop) {
            $mc = Num::mostCommon($pracTop);
            $who = (string) array_key_first($mc);
            $add('practice', 'Practice Hero', 'wrench', $who, (string) $mc[$who], 'rounds topping the team practice times');
        }

        $s = Num::maxBy($standings, fn($s) => $s['inc_per_race'] ?? 0);
        $add('insurance', 'Insurance Premium', 'shield-alert', $s['driver'], $f($s['inc_per_race'], 1),
            'average incidents per race, the highest on the team');
        return $A;
    }
}
