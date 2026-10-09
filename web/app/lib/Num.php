<?php
/* Number helpers shared by the stats compiler and the views. Semantics follow the v2 TypeScript
   compiler (and through it the v1 Python build) so every published number stays the same. */
declare(strict_types=1);

namespace PitWall;

final class Num
{
    /** JavaScript's Number#toFixed: rounds the exact binary value, ties away from zero. */
    public static function fixed(float|int $x, int $d): string
    {
        $x = (float) $x;
        if (!is_finite($x)) return is_nan($x) ? 'NaN' : ($x > 0 ? 'Infinity' : '-Infinity');
        $neg = $x < 0;
        $a = abs($x);
        // sprintf rounds correctly but sends exact ties to even; JS sends them away from zero
        $hp = sprintf('%.' . min(53, $d + 25) . 'f', $a);
        [$int, $frac] = array_pad(explode('.', $hp, 2), 2, '');
        $tail = substr($frac, $d);
        if ($tail !== '' && $tail[0] === '5' && rtrim(substr($tail, 1), '0') === '') {
            $digits = $int . substr($frac, 0, $d);
            for ($i = strlen($digits) - 1; $i >= 0; $i--) {
                if ($digits[$i] === '9') { $digits[$i] = '0'; continue; }
                $digits[$i] = chr(ord($digits[$i]) + 1);
                break;
            }
            if ($i < 0) $digits = '1' . $digits;
            $cut = strlen($digits) - $d;
            $out = $d > 0 ? substr($digits, 0, $cut) . '.' . substr($digits, $cut) : $digits;
        } else {
            $out = sprintf('%.' . $d . 'f', $a);
        }
        return ($neg ? '-' : '') . $out;
    }

    /** Round like the compiler's rnd(): Number(x.toFixed(n)), null for null or non-finite. */
    public static function rnd(float|int|null $x, int $n = 3): ?float
    {
        if ($x === null || !is_finite((float) $x)) return null;
        $v = (float) self::fixed($x, $n);
        return $v == 0.0 ? 0.0 : $v; // no negative zero
    }

    /** iRacing times are 1/10000 s; -1 or 0 means no time. */
    public static function secs(mixed $t): ?float
    {
        return (is_int($t) || is_float($t)) && $t > 0 ? self::rnd($t / 10000, 4) : null;
    }

    /** Compensated (Neumaier) summation, as Python's sum() and the TS compiler use. */
    public static function sum(array $xs): int|float
    {
        $s = 0; $c = 0;
        foreach ($xs as $x) {
            $t = $s + $x;
            $c += abs($s) >= abs($x) ? ($s - $t) + $x : ($x - $t) + $s;
            $s = $t;
        }
        return $c && is_finite((float) $c) ? $s + $c : $s;
    }

    public static function mean(array $xs): ?float
    {
        $v = array_values(array_filter($xs, fn($x) => $x !== null));
        return $v ? self::sum($v) / count($v) : null;
    }

    /** Population standard deviation (statistics.pstdev). */
    public static function pstdev(array $xs): float
    {
        $m = self::sum($xs) / count($xs);
        return sqrt(self::sum(array_map(fn($x) => ($x - $m) ** 2, $xs)) / count($xs));
    }

    /** First element with the smallest key. */
    public static function minBy(array $xs, callable $key): mixed
    {
        $best = null; $bk = INF; $found = false;
        foreach ($xs as $x) { $k = $key($x); if (!$found || $k < $bk) { $best = $x; $bk = $k; $found = true; } }
        return $best;
    }

    /** First element with the largest key. */
    public static function maxBy(array $xs, callable $key): mixed
    {
        $best = null; $bk = -INF; $found = false;
        foreach ($xs as $x) { $k = $key($x); if (!$found || $k > $bk) { $best = $x; $bk = $k; $found = true; } }
        return $best;
    }

    /** Compare tuples element by element (multi-key sorts). */
    public static function cmpTuple(array $a, array $b): int
    {
        foreach ($a as $i => $v) {
            if ($v < $b[$i]) return -1;
            if ($v > $b[$i]) return 1;
        }
        return 0;
    }

    /** Counter(...).most_common(): count descending, ties in first-seen order. */
    public static function mostCommon(array $xs): array
    {
        $counts = [];
        foreach ($xs as $x) $counts[$x] = ($counts[$x] ?? 0) + 1;
        $keys = array_keys($counts);
        $order = array_flip($keys);
        usort($keys, fn($a, $b) => $counts[$b] <=> $counts[$a] ?: $order[$a] <=> $order[$b]);
        $out = [];
        foreach ($keys as $k) $out[(string) $k] = $counts[$k];
        return $out;
    }

    public static function slug(string $s): string
    {
        $s = preg_replace('/[^\p{L}\p{N}]/u', '-', $s) ?? $s;
        return trim(mb_strtolower($s, 'UTF-8'), '-');
    }

    /** Smallest of the truthy values, or null (the compiler's minTime). */
    public static function minTruthy(array $xs): ?float
    {
        $v = array_filter($xs, fn($x) => (bool) $x);
        return $v ? min($v) : null;
    }

    /** Smallest non-null value, or null. */
    public static function minOf(array $xs): ?float
    {
        $v = array_filter($xs, fn($x) => $x !== null);
        return $v ? min($v) : null;
    }

    /** JS Math.round: halves go up. */
    public static function jsRound(float $x): int
    {
        return (int) floor($x + 0.5);
    }

    /** Sorted unique strings, in JavaScript's default sort order. */
    public static function uniqueSorted(array $xs): array
    {
        $u = array_values(array_unique(array_map('strval', $xs)));
        sort($u, SORT_STRING);
        return $u;
    }
}
