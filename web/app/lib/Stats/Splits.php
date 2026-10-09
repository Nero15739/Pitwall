<?php
/* Sector splits from a replay's fast-forward pass → per-driver bests, averages, theoretical
   laps, and a plain-language analysis of where each driver gains and loses time. */
declare(strict_types=1);

namespace PitWall\Stats;

use PitWall\Num;

final class Splits
{
    private const CLEAN_WITHIN = 1.07;          // laps within 107% of the driver's best count toward averages
    private const FASTER_THAN_OFFICIAL = 0.1;   // a lap this much quicker than the official best was cut or mistimed

    private static function first(string $n): string { return explode(' ', $n)[0]; }
    private static function s3(float $x): string { return Num::fixed($x, 3) . 's'; }
    private static function lapTime(float $t): string
    {
        $m = (int) floor($t / 60);
        return $m . ':' . str_pad(Num::fixed($t - $m * 60, 3), 6, '0', STR_PAD_LEFT);
    }

    public static function round(int $round, array $race, array $splits, array $incidents = []): ?array
    {
        $sectors = $splits['sectors'];
        $n = count($sectors);
        $entries = [];
        foreach ($race['entries'] as $e) $entries[$e['cust_id']] = $e;
        $byDriver = [];
        foreach ($splits['laps'] as $l) {
            if (count($l['sectors']) !== $n) continue;
            foreach ($l['sectors'] as $s) if (!($s > 0)) continue 2;
            $byDriver[$l['cust_id']][] = $l;
        }
        $incByDriver = [];
        foreach ($incidents as $i) $incByDriver[$i['cust_id']][] = $i;
        $sectorOf = function (float $pct) use ($sectors): int {
            $k = 0;
            foreach ($sectors as $j => $s) if ($pct >= $s) $k = $j;
            return $k;
        };

        $all = [];
        foreach ($byDriver as $cid => $laps) {
            $e = $entries[$cid] ?? null;
            if (!$e) continue;
            $timed = array_values(array_filter($laps, fn($l) => !$l['pit'] && !($e['best'] && $l['lap_time'] < $e['best'] - self::FASTER_THAN_OFFICIAL)));
            if (!$timed) continue;
            // a sector where the car left the track or spun isn't a fair time (a cut chicane is quicker)
            $dirty = [];
            foreach ($timed as $li => $l) {
                $set = [];
                if (($l['start'] ?? null) !== null) {
                    foreach ($incByDriver[$cid] ?? [] as $i) {
                        if ($i['t'] >= $l['start'] && $i['t'] <= $l['start'] + $l['lap_time']) $set[$sectorOf((float) $i['pct'])] = true;
                    }
                }
                $dirty[$li] = $set;
            }
            $bestLap = min(array_map(fn($l) => $l['lap_time'], $timed));
            $clean = [];
            foreach ($timed as $li => $l) if ($l['lap_time'] <= $bestLap * self::CLEAN_WITHIN && !$dirty[$li]) $clean[] = $l;
            $best = [];
            $avg = [];
            for ($k = 0; $k < $n; $k++) {
                $fair = [];
                foreach ($timed as $li => $l) if (!isset($dirty[$li][$k])) $fair[] = $l['sectors'][$k];
                $best[] = $fair ? Num::rnd(min($fair), 3) : null;
                $avg[] = Num::rnd(Num::mean(array_map(fn($l) => $l['sectors'][$k], $clean)), 3);
            }
            $complete = !in_array(null, $best, true);
            $all[] = [
                'driver' => $e['name'], 'team' => !$e['ai'], 'ai' => $e['ai'],
                'best' => $best,
                'avg' => $avg,
                'best_lap' => Num::rnd($bestLap, 3),
                'theoretical' => $complete ? Num::rnd(Num::sum($best), 3) : null,
                'clean_laps' => count($clean),
            ];
        }
        $byTheo = fn($a, $b) => ($a['theoretical'] ?? 1e9) <=> ($b['theoretical'] ?? 1e9);
        $team = array_values(array_filter($all, fn($d) => $d['team']));
        usort($team, $byTheo);
        if (!$team) return null;
        $ais = array_values(array_filter($all, fn($d) => $d['ai']));
        usort($ais, $byTheo);
        $ai = $ais[0] ?? null;
        $teamBest = [];
        $fieldBest = [];
        for ($k = 0; $k < $n; $k++) {
            $teamBest[] = Num::minOf(array_map(fn($d) => $d['best'][$k], $team));
            $fieldBest[] = Num::minOf(array_map(fn($d) => $d['best'][$k], $all));
        }

        return [
            'round' => $round,
            'sectors' => $sectors,
            'drivers' => $ai ? [...$team, $ai] : $team,
            'team_best' => $teamBest,
            'field_best' => $fieldBest,
            'analysis' => self::analyse($team, $ai, $teamBest),
        ];
    }

    private static function analyse(array $team, ?array $ai, array $teamBest): array
    {
        $n = count($teamBest);
        $ideal = !in_array(null, $teamBest, true) ? Num::rnd(Num::sum($teamBest), 3) : null;
        $withLap = array_values(array_filter($team, fn($d) => $d['best_lap'] !== null));
        usort($withLap, fn($a, $b) => $a['best_lap'] <=> $b['best_lap']);
        $fastest = $withLap[0] ?? null;

        $drivers = [];
        foreach ($team as $d) {
            $gaps = [];
            foreach ($d['best'] as $k => $b) $gaps[] = $b !== null && $teamBest[$k] !== null ? Num::rnd($b - $teamBest[$k], 3) : null;
            $deficit = $d['theoretical'] !== null && $ideal !== null ? Num::rnd($d['theoretical'] - $ideal, 3) : null;
            $share = array_map(fn($g) => $g !== null && $deficit ? Num::rnd($g / $deficit, 3) : null, $gaps);
            $idx = [];
            foreach ($gaps as $k => $g) if ($g !== null) $idx[] = ['g' => $g, 'k' => $k];
            $wob = [];
            foreach ($d['avg'] as $k => $a) {
                if ($a !== null && $d['best'][$k] !== null) $wob[] = ['s' => ($a - $d['best'][$k]) / $d['best'][$k], 'k' => $k];
            }
            $pick = function (array $xs, string $f, bool $max) {
                $best = $xs[0];
                foreach (array_slice($xs, 1) as $x) if ($max ? $x[$f] > $best[$f] : $x[$f] < $best[$f]) $best = $x;
                return $best['k'];
            };
            $drivers[] = [
                'driver' => $d['driver'],
                'deficit' => $deficit,
                'gaps' => $gaps,
                'share' => $share,
                'weakest' => $idx && $deficit ? $pick($idx, 'g', true) : null,
                'strongest' => $idx ? $pick($idx, 'g', false) : null,
                'on_table' => $d['best_lap'] !== null && $d['theoretical'] !== null ? Num::rnd($d['best_lap'] - $d['theoretical'], 3) : null,
                'wobbliest' => $wob ? $pick($wob, 's', true) : null,
            ];
        }

        // the sector where the team's best times differ most
        $decisive = null;
        for ($k = 0; $k < $n; $k++) {
            $v = array_values(array_filter(array_map(fn($d) => $d['best'][$k], $team), fn($x) => $x !== null));
            if (count($v) < 2) continue;
            $spread = Num::rnd(max($v) - min($v), 3);
            if (!$decisive || $spread > $decisive['spread']) $decisive = ['sector' => $k, 'spread' => $spread];
        }
        $aiGap = [];
        foreach ($teamBest as $k => $t) $aiGap[] = $t !== null && ($ai['best'][$k] ?? null) !== null ? Num::rnd($t - $ai['best'][$k], 3) : null;

        // ---- plain-language findings
        $I = [];
        if ($ideal !== null && ($fastest['best_lap'] ?? null) !== null) {
            $I[] = ['driver' => null, 'text' => sprintf("Putting the team's best sectors together gives a %s, %s quicker than the fastest real lap (%s, %s).",
                self::lapTime($ideal), self::s3($fastest['best_lap'] - $ideal), self::first($fastest['driver']), self::lapTime($fastest['best_lap']))];
        }
        if ($decisive && $decisive['spread'] > 0) {
            $I[] = ['driver' => null, 'text' => sprintf('Sector %d separates the team most: %s between the quickest and slowest best times.',
                $decisive['sector'] + 1, self::s3($decisive['spread']))];
        }
        if ($ai) {
            $worse = [];
            foreach ($aiGap as $k => $g) if ($g !== null && $g > 0) $worse[] = ['g' => $g, 'k' => $k];
            if (!$worse) {
                $I[] = ['driver' => null, 'text' => sprintf("The team's best beats the fastest AI (%s) in every sector.", self::first($ai['driver']))];
            } else {
                $w = $worse[0];
                foreach ($worse as $x) if ($x['g'] > $w['g']) $w = $x;
                $I[] = ['driver' => null, 'text' => sprintf("The fastest AI is quicker than the team's best in %d of %d sectors, most in sector %d (%s).",
                    count($worse), $n, $w['k'] + 1, self::s3($w['g']))];
            }
        }
        foreach ($drivers as $a) {
            if ($a['deficit'] === null) continue;
            $first = self::first($a['driver']);
            if ($a['deficit'] <= 0.0005) {
                $I[] = ['driver' => $a['driver'], 'text' => "{$first} owns the team's ideal lap"
                    . ($a['on_table'] ? ', but left ' . self::s3($a['on_table']) . ' on the table versus that theoretical best' : '') . '.'];
                continue;
            }
            $parts = ["{$first} is " . self::s3($a['deficit']) . " off the team's ideal lap"];
            if ($a['weakest'] !== null) {
                $parts[] = Num::jsRound(($a['share'][$a['weakest']] ?? 0) * 100) . '% of it in sector ' . ($a['weakest'] + 1)
                    . ' (' . self::s3($a['gaps'][$a['weakest']] ?? 0) . ')';
            }
            $text = implode(', ', $parts) . '.';
            if ($a['strongest'] !== null && $a['strongest'] !== $a['weakest']) {
                $g = $a['gaps'][$a['strongest']] ?? 0;
                $text .= $g <= 0.0005
                    ? ' Fastest in the team through sector ' . ($a['strongest'] + 1) . '.'
                    : ' Closest to the pace in sector ' . ($a['strongest'] + 1) . ' (' . self::s3($g) . ').';
            }
            if ($a['on_table'] !== null && $a['on_table'] > 0.05) $text .= ' ' . self::s3($a['on_table']) . ' left on the table versus their own theoretical best.';
            $I[] = ['driver' => $a['driver'], 'text' => $text];
        }

        return [
            'ideal' => $ideal,
            'fastest_lap' => ($fastest['best_lap'] ?? null) !== null ? ['driver' => $fastest['driver'], 'time' => $fastest['best_lap']] : null,
            'decisive' => $decisive,
            'ai_gap' => $aiGap,
            'drivers' => $drivers,
            'insights' => $I,
        ];
    }
}
