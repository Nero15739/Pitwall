<?php
/* Display formatting. No stats are computed while rendering; these only shape numbers for reading. */
declare(strict_types=1);

namespace PitWall;

final class F
{
    public const DASH = '–';

    public static function lap(float|int|null $t): string
    {
        if ($t === null) return self::DASH;
        $m = (int) floor($t / 60);
        return $m . ':' . str_pad(Num::fixed($t - $m * 60, 3), 6, '0', STR_PAD_LEFT);
    }

    /** Signed number with a real minus sign: +0.123 / −0.123 / ±0.000 */
    public static function sgn(float|int|null $x, int $d = 3, string $unit = ''): string
    {
        if ($x === null) return self::DASH;
        return ($x > 0 ? '+' : ($x < 0 ? '−' : '±')) . Num::fixed(abs($x), $d) . $unit;
    }

    public static function pct(float|int|null $x, int $d = 2): string
    {
        return $x === null ? self::DASH : Num::fixed($x, $d) . '%';
    }

    public static function fix(mixed $x, int $d = 1): string
    {
        return $x === null || !is_numeric($x) || !is_finite((float) $x) ? self::DASH : Num::fixed((float) $x, $d);
    }

    public static function ord(?int $n): string
    {
        return $n === null ? self::DASH : "P{$n}";
    }

    public static function per10(float|int|null $perLap, int $d = 1): string
    {
        return $perLap === null ? self::DASH : Num::fixed($perLap * 10, $d);
    }

    public static function s3(float|int|null $x): string
    {
        return $x === null ? self::DASH : Num::fixed($x, 3);
    }

    /** A schedule date with no time ("2026-11-02") is a calendar day, the same day for every reader. */
    public static function dayOnly(string $iso): bool
    {
        return strlen($iso) === 10;
    }

    /**
     * A date the browser re-renders in the reader's own locale and time zone.
     * $fmt: long (2 Nov 2026), short (2 Nov), when (Mon 2 Nov · 7:30 pm), daytime (Mon · 7:30 pm),
     * weekday (Mon). A time is only shown when the date has one.
     */
    public static function date(string $iso, bool|string $fmt = true): string
    {
        $fmt = $fmt === true ? 'long' : ($fmt === false ? 'short' : $fmt);
        $day = self::dayOnly($iso);
        $ts = strtotime($iso);
        $at = (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone($day ? 'UTC' : (string) Config::get('timezone', 'UTC')));
        $text = match ($fmt) {
            'when' => $at->format('D j M') . ($day ? '' : ' · ' . $at->format('g:i a')),
            'daytime' => $at->format('D') . ($day ? '' : ' · ' . $at->format('g:i a')),
            'weekday' => $at->format('D'),
            'short' => $at->format('j M'),
            default => $at->format('j M Y'),
        };
        return '<time datetime="' . e($day ? $iso : gmdate('c', $ts)) . '" data-fmt="' . e($fmt) . '"' . ($day ? ' data-day' : '') . '>' . e($text) . '</time>';
    }

    /** Whole days from today to a date (negative once it has passed), by the calendar in UTC. */
    public static function daysUntil(string $iso, ?int $now = null): int
    {
        $day = fn(int $ts) => intdiv($ts - ($ts % 86400), 86400);
        return $day(strtotime($iso)) - $day($now ?? time());
    }

    /** "in 9 days", "tomorrow", "today", "3 days ago". The browser recounts it in the reader's own time zone. */
    public static function until(string $iso): string
    {
        $d = self::daysUntil($iso);
        $text = match (true) {
            $d === 0 => 'today',
            $d === 1 => 'tomorrow',
            $d === -1 => 'yesterday',
            $d > 1 => "in {$d} days",
            default => abs($d) . ' days ago',
        };
        return '<span data-until="' . e(self::dayOnly($iso) ? $iso : gmdate('c', strtotime($iso))) . '"' . (self::dayOnly($iso) ? ' data-day' : '') . '>' . e($text) . '</span>';
    }

    /** 1075 → "1,075" */
    public static function int(int|float|null $n): string
    {
        return $n === null ? self::DASH : number_format((float) $n);
    }

    /** An estimated rating: rounded to the nearest ten and marked as approximate. */
    public static function approx(int|float|null $n): string
    {
        return $n === null ? self::DASH : '≈ ' . number_format(round((float) $n, -1));
    }

    public static function carShort(?string $c): string
    {
        $c ??= '';
        $s = trim(preg_replace(['/\s+GT3.*$/i', '/\s+\(.*\)$/'], '', $c) ?? $c);
        return $s !== '' ? $s : $c;
    }

    private const STOP = ['circuit', 'circuito', 'de', 'del', 'di', 'the', 'autodromo', 'autódromo', 'nazionale', 'internazionale', 'international', 'raceway', 'park', 'motorsport', 'speedway'];

    /** Circuits known by a name that isn't their first plain word. */
    private const NICK = ['24 heures du mans' => 'Le Mans', 'enzo e dino ferrari' => 'Imola', 'nordschleife' => 'Nordschleife', 'red bull ring' => 'Red Bull'];

    /** One word for tight spaces: "Circuit de Barcelona Catalunya" → "Barcelona". */
    public static function trackTiny(?string $t): string
    {
        $t ??= '';
        $low = mb_strtolower($t);
        foreach (self::NICK as $has => $nick) if (str_contains($low, $has)) return $nick;
        foreach (preg_split('/[\s-]+/u', $t) ?: [] as $w) {
            if ($w !== '' && !in_array(mb_strtolower($w), self::STOP, true)) return $w;
        }
        return $t;
    }

    public static function firstName(string $n): string
    {
        return explode(' ', $n)[0];
    }

    public static function km(float|int|null $m): ?string
    {
        if ($m === null) return null;
        return $m >= 1000 ? Num::fixed($m / 1000, 2) . ' km' : (string) Num::jsRound((float) $m) . ' m';
    }

    public static function ago(?string $iso): string
    {
        if (!$iso) return 'never';
        $s = time() - strtotime($iso);
        return match (true) {
            $s < 60 => 'just now',
            $s < 3600 => intdiv($s, 60) . ' min ago',
            $s < 86400 => intdiv($s, 3600) . ' h ago',
            $s < 86400 * 30 => intdiv($s, 86400) . ' d ago',
            default => gmdate('j M Y', strtotime($iso)),
        };
    }

    public static function bytes(int $b): string
    {
        return $b >= 1048576 ? Num::fixed($b / 1048576, 1) . ' MB' : Num::fixed($b / 1024, 0) . ' KB';
    }

    public static function plural(int $n, string $one, ?string $many = null): string
    {
        return $n . ' ' . ($n === 1 ? $one : ($many ?? $one . 's'));
    }
}
