<?php
/* Incident markers → hotspot density around the lap and the top clusters.
   Everything wraps at the start/finish line, so the lap is treated as a circle. */
declare(strict_types=1);

namespace PitWall\Stats;

use PitWall\Num;

final class Hotspots
{
    public const BINS = 200;
    private const SIGMA = 1.5;      // smoothing, in bins (0.75% of a lap)
    private const WINDOW = 4;       // a hotspot collects markers within ±4 bins (±2% of a lap)
    private const MIN_SEP = 9;      // peaks closer than this merge into one hotspot
    private const MAX_HOTSPOTS = 5;

    private static ?array $kernel = null;

    private static function kernel(): array
    {
        if (self::$kernel !== null) return self::$kernel;
        $r = (int) ceil(self::SIGMA * 3);
        $k = [];
        for ($i = 0; $i <= 2 * $r; $i++) $k[] = exp(-(($i - $r) ** 2) / (2 * self::SIGMA ** 2));
        $s = 0.0;
        foreach ($k as $v) $s += $v;
        return self::$kernel = array_map(fn($v) => $v / $s, $k);
    }

    private static function binOf(float $pct): int
    {
        return min(self::BINS - 1, (int) floor(fmod(fmod($pct, 1) + 1, 1) * self::BINS));
    }

    private static function wrap(int $i): int
    {
        return (($i % self::BINS) + self::BINS) % self::BINS;
    }

    /** Shortest distance between two lap positions, in laps (0..0.5). */
    private static function lapDist(float $a, float $b): float
    {
        $d = fmod(abs($a - $b), 1);
        return min($d, 1 - $d);
    }

    private static function density(array $events): array
    {
        $raw = array_fill(0, self::BINS, 0);
        foreach ($events as $e) $raw[self::binOf((float) $e['pct'])]++;
        $K = self::kernel();
        $r = intdiv(count($K) - 1, 2);
        $out = [];
        for ($i = 0; $i < self::BINS; $i++) {
            $acc = 0.0;
            foreach ($K as $j => $k) $acc += $k * $raw[self::wrap($i + $j - $r)];
            $out[] = Num::rnd($acc, 3);
        }
        return $out;
    }

    /** Circular mean of lap positions (handles clusters that straddle the line). */
    private static function circularMean(array $pcts): float
    {
        $s = 0.0; $c = 0.0;
        foreach ($pcts as $p) $s += sin(2 * M_PI * $p);
        foreach ($pcts as $p) $c += cos(2 * M_PI * $p);
        return fmod(fmod(atan2($s, $c) / (2 * M_PI), 1) + 1, 1);
    }

    private static function peaks(array $events, array $d, ?float $lengthM): array
    {
        if (!$events) return [];
        $B = self::BINS;
        $cands = [];
        foreach ($d as $i => $v) {
            if ($v > 0 && $v >= $d[self::wrap($i - 1)] && $v > $d[self::wrap($i + 1)]) $cands[] = ['v' => $v, 'i' => $i];
        }
        usort($cands, fn($a, $b) => $b['v'] <=> $a['v']);
        $picked = [];
        foreach ($cands as $c) {
            $ok = true;
            foreach ($picked as $p) if (min(abs($p - $c['i']), $B - abs($p - $c['i'])) < self::MIN_SEP) { $ok = false; break; }
            if ($ok) $picked[] = $c['i'];
        }
        $out = [];
        foreach ($picked as $i) {
            $centre = ($i + 0.5) / $B;
            $near = array_values(array_filter($events, fn($e) => self::lapDist((float) $e['pct'], $centre) <= self::WINDOW / $B));
            if (count($near) < 2) continue;
            $pct = self::circularMean(array_map(fn($e) => (float) $e['pct'], $near));
            // spread relative to the centre so a cluster across the line still reads from → to
            $rel = array_map(function ($e) use ($pct) {
                $x = $e['pct'] - $pct;
                if ($x > 0.5) $x -= 1;
                if ($x < -0.5) $x += 1;
                return $x;
            }, $near);
            $tally = [];
            foreach ($near as $e) {
                $tally[$e['driver']] ??= ['name' => $e['driver'], 'n' => 0, 'team' => $e['team'], 'ai' => $e['ai']];
                $tally[$e['driver']]['n']++;
            }
            $drivers = array_values($tally);
            usort($drivers, fn($a, $b) => $b['n'] <=> $a['n'] ?: (strcasecmp($a['name'], $b['name']) ?: strcmp($a['name'], $b['name'])));
            $out[] = [
                'pct' => Num::rnd($pct, 4),
                'from' => Num::rnd(fmod(fmod($pct + min($rel), 1) + 1, 1), 4),
                'to' => Num::rnd(fmod(fmod($pct + max($rel), 1) + 1, 1), 4),
                'count' => count($near),
                'distance_m' => $lengthM ? Num::jsRound($pct * $lengthM) : null,
                'drivers' => $drivers,
            ];
        }
        usort($out, fn($a, $b) => $b['count'] <=> $a['count']);
        return array_slice($out, 0, self::MAX_HOTSPOTS);
    }

    public static function summarize(array $events, int|float|null $lengthM): array
    {
        $team = array_values(array_filter($events, fn($e) => $e['team']));
        $dTeam = self::density($team);
        $dField = self::density($events);
        $len = $lengthM === null ? null : (float) $lengthM;
        return [
            'n_team' => count($team),
            'n_field' => count($events),
            'density_team' => $dTeam,
            'density_field' => $dField,
            'top_team' => self::peaks($team, $dTeam, $len),
            'top_field' => self::peaks($events, $dField, $len),
        ];
    }

    public static function set(array $events, int|float|null $lengthM): array
    {
        return self::summarize($events, $lengthM) + ['events' => array_values($events), 'length_m' => $lengthM];
    }
}
