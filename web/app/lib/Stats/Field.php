<?php
/* How strong was the field? iRacing gives AI cars no iRating, so a round's AI strength is
   estimated from the result: the rating at which the AI cars' finishes against the team's rated
   drivers would be par, using iRacing's own expected-score curve. An indication, not a fact. */
declare(strict_types=1);

namespace PitWall\Stats;

use PitWall\Num;

final class Field
{
    private const BR = 1600 / M_LN2;   // iRacing's rating scale: 1600 points doubles the odds
    private const MAX = 12000;

    /** Bradley-Terry strength behind an iRating: the odds of a beating b are strength(a) / strength(b). */
    private static function strength(float $r): float
    {
        return exp($r / self::BR) - 1;
    }

    /** Strength of field as iRacing computes it. */
    public static function sof(array $ratings): ?float
    {
        if (!$ratings) return null;
        $s = 0.0;
        foreach ($ratings as $r) $s += exp(-$r / self::BR);
        return self::BR * log(count($ratings) / $s);
    }

    /** Places a driver rated $r is expected to take from these opponents. */
    private static function expected(float $r, array $others): float
    {
        $a = self::strength($r);
        $t = 0.0;
        foreach ($others as $o) {
            $b = self::strength($o);
            $t += $a + $b > 0 ? $a / ($a + $b) : 0.5;
        }
        return $t;
    }

    /** Root of an increasing function on 0..MAX, by bisection. */
    private static function solve(callable $f): float
    {
        $lo = 0.0;
        $hi = (float) self::MAX;
        for ($i = 0; $i < 60; $i++) {
            $m = ($lo + $hi) / 2;
            if ($f($m) > 0) $hi = $m; else $lo = $m;
        }
        return ($lo + $hi) / 2;
    }

    private static function median(array $xs): ?float
    {
        $v = array_values(array_filter($xs, fn($x) => (bool) $x));
        if (!$v) return null;
        sort($v);
        $n = count($v);
        return Num::rnd($n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2, 4);
    }

    /** One race's entries → the strength of the field the team raced against. */
    public static function round(array $ents): array
    {
        $rated = array_values(array_filter($ents, fn($e) => !$e['ai'] && $e['irating'] !== null));
        $ai = array_values(array_filter($ents, fn($e) => $e['ai']));
        $hs = array_map(fn($e) => (float) $e['irating'], $rated);
        $teamSof = self::sof($hs);
        $out = [
            'rated' => count($rated),
            'team_sof' => $teamSof === null ? null : Num::jsRound($teamSof),
            'ai_rating' => null, 'ai_low' => null, 'ai_high' => null, 'sof_est' => null,
            'ai_wins' => 0, 'pairs' => count($ai) * count($rated),
            'ai_median_best' => self::median(array_column($ai, 'best')),
            'team_median_best' => self::median(array_column(array_filter($ents, fn($e) => !$e['ai']), 'best')),
            'groups' => [],
        ];
        if (!$rated || !$ai) return $out;

        // every AI car against every rated team driver: who finished ahead?
        $tally = [];
        foreach ($ai as $a) {
            $beat = count(array_filter($rated, fn($h) => $a['finish'] < $h['finish']));
            $out['ai_wins'] += $beat;
            $tally[$beat] = ($tally[$beat] ?? 0) + 1;
        }
        // half a result is held back so a clean sweep either way still gives a finite number
        $target = min($out['pairs'] - 0.5, max(0.5, $out['ai_wins']));
        $n = count($ai);
        $field = self::solve(fn($r) => $n * self::expected($r, $hs) - $target);
        $out['ai_rating'] = Num::jsRound($field);

        // AI cars grouped by how many team drivers they beat; one win and one loss against an
        // average AI car keeps a group that beat everyone (or no one) on the scale
        krsort($tally);
        $all = $hs;
        foreach ($tally as $beat => $cars) {
            $r = Num::jsRound(self::solve(fn($r) => self::expected($r, $hs) + 2 * self::expected($r, [$field]) - ($beat + 1)));
            $out['groups'][] = ['beat' => $beat, 'cars' => $cars, 'rating' => $r];
            for ($k = 0; $k < $cars; $k++) $all[] = (float) $r;
        }
        $ratings = array_column($out['groups'], 'rating');
        $out['ai_low'] = min($ratings);
        $out['ai_high'] = max($ratings);
        $out['sof_est'] = Num::jsRound(self::sof($all));
        return $out;
    }
}
