<?php
/* Season awards: the numbers behind the bragging rights. */
declare(strict_types=1);

namespace PitWall\Stats;

use PitWall\Num;

final class Awards
{
    /** $pages: the season's track pages, for what the harvested replays add (sector bests, incidents spotted). */
    public static function compute(array $results, array $rounds, array $standings, array $per, array $pages = []): array
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

        $x = Num::minBy($results, fn($x) => $x['gained']);
        if ($x['gained'] < 0) {
            $add('reverse', 'Reverse Gear', 'trend-down', $x['driver'], '−' . -$x['gained'],
                "places lost in one race: P{$x['start']} → P{$x['finish']} at {$track[$x['round']]}");
        }

        // from the replay's lap chart, in the rounds that have one
        $charted = array_values(array_filter($results, fn($x) => $x['lap1_pos'] !== null && $x['low_pos'] !== null));
        if ($charted) {
            $x = Num::maxBy($charted, fn($x) => $x['start'] - $x['lap1_pos']);
            if ($x['start'] > $x['lap1_pos']) {
                $add('lap1hero', 'Lap One Hero', 'zap', $x['driver'], '+' . ($x['start'] - $x['lap1_pos']),
                    "places gained on the opening lap at {$track[$x['round']]}: P{$x['start']} → P{$x['lap1_pos']}");
            }
            $x = Num::minBy($charted, fn($x) => $x['start'] - $x['lap1_pos']);
            if ($x['start'] < $x['lap1_pos']) {
                $add('lap1victim', 'Lap One Victim', 'alert', $x['driver'], '−' . ($x['lap1_pos'] - $x['start']),
                    "places lost on the opening lap at {$track[$x['round']]}: P{$x['start']} → P{$x['lap1_pos']}");
            }
            $x = Num::maxBy($charted, fn($x) => $x['low_pos'] - $x['finish']);
            if ($x['low_pos'] > $x['finish']) {
                $add('comeback', 'Comeback', 'corner-up', $x['driver'], '+' . ($x['low_pos'] - $x['finish']),
                    "places recovered at {$track[$x['round']]}: as low as P{$x['low_pos']}, finished P{$x['finish']}");
            }
            $x = Num::maxBy($charted, fn($x) => $x['pos_changes'] ?? 0);
            if ($x['pos_changes']) {
                $add('yoyo', 'Yo-Yo', 'activity', $x['driver'], (string) $x['pos_changes'],
                    "laps ended in a different place from the lap before, at {$track[$x['round']]}");
            }
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

        // from harvested replays: who holds the team's best time in each sector, and the incidents spotted
        $kings = [];
        foreach ($pages as $p) foreach ($p['splits'] as $sp) {
            $team = array_values(array_filter($sp['drivers'], fn($d) => $d['team']));
            foreach (array_keys($sp['sectors']) as $k) {
                $timed = array_values(array_filter($team, fn($d) => $d['best'][$k] !== null));
                if (count($timed) > 1) $kings[] = Num::minBy($timed, fn($d) => $d['best'][$k])['driver'];
            }
        }
        if ($kings) {
            $mc = Num::mostCommon($kings);
            $who = (string) array_key_first($mc);
            $add('sectors', 'Sector King', 'gauge', $who, (string) $mc[$who], 'team-best sectors, of the ' . count($kings) . ' timed in replays');
        }
        $spotted = [];
        foreach ($pages as $p) foreach ($p['hotspots']['events'] ?? [] as $e) if ($e['team']) $spotted[] = $e;
        foreach ([
            ['off', 'lawnmower', 'Lawnmower', 'leaf', 'trips onto the grass or gravel, spotted in replays'],
            ['spin', 'spin', 'Spin Doctor', 'refresh', 'spins, spotted in replays'],
        ] as [$kind, $key, $title, $icon, $detail]) {
            $who = array_column(array_filter($spotted, fn($e) => $e['kind'] === $kind), 'driver');
            if (!$who) continue;
            $mc = Num::mostCommon($who);
            $name = (string) array_key_first($mc);
            $add($key, $title, $icon, $name, (string) $mc[$name], $detail);
        }

        $qualAvg = fn(string $d) => Num::mean(array_map(fn($x) => $x['team_qual'], array_filter($per[$d] ?? [], fn($x) => (bool) $x['team_qual'])));
        $qa = Num::minBy($standings, fn($s) => $qualAvg($s['driver']) ?: 99);
        $qv = $qualAvg($qa['driver']);
        if ($qv !== null) $add('quali', 'Quali Ace', 'flag', $qa['driver'], $f($qv, 1), 'average qualifying position within the team');

        $s = Num::maxBy($standings, fn($s) => $s['avg_gained'] ?: -99);
        if ($s['avg_gained'] !== null) $add('racecraft', 'Sunday Specialist', 'trend', $s['driver'], $signed($s['avg_gained'], 1), 'average places gained from grid to flag');

        $s = Num::maxBy($standings, fn($s) => $s['laps_led']);
        if ($s['laps_led']) $add('leader', 'Front Runner', 'crown', $s['driver'], (string) $s['laps_led'], 'laps led this season');

        $s = Num::maxBy($standings, fn($s) => $s['laps_team_led']);
        if ($s['laps_team_led']) $add('teamlead', 'Team Leader', 'users', $s['driver'], (string) $s['laps_team_led'], 'laps as the first team car on track, in the rounds with a harvested replay');

        $s = Num::maxBy($standings, fn($s) => $s['laps_last']);
        if ($s['laps_last']) $add('tail', 'Tail Gunner', 'anchor', $s['driver'], (string) $s['laps_last'], 'laps run last on track, in the rounds with a harvested replay');

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

        // and for anyone the judges couldn't find anything for
        $winners = [];
        foreach ($A as $a) foreach (explode(' vs ', $a['driver']) as $who) $winners[$who] = true;
        $k = 0;
        foreach ($standings as $s) {
            if (isset($winners[$s['driver']])) continue;
            $add('participation', 'Participation Trophy', 'ghost', $s['driver'], '0', self::CONSOLATION[$k++ % count(self::CONSOLATION)]);
        }
        return $A;
    }

    /** One each, in standings order, for drivers with no other award. */
    private const CONSOLATION = [
        'awards won. Turned up, did the laps, and left the trophy cabinet in showroom condition.',
        'awards won. The judges checked every stat twice. Thanks for coming.',
        'awards won. Generously left all the silverware for everyone else.',
        'awards won. Not the fastest, cleanest, wildest or luckiest. Just… present.',
    ];
}
