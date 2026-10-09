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

    /** A date the browser re-renders in the reader's own locale and time zone. */
    public static function date(string $iso, bool $year = true): string
    {
        $ts = strtotime($iso);
        $tz = new \DateTimeZone((string) Config::get('timezone', 'UTC'));
        $text = (new \DateTimeImmutable('@' . $ts))->setTimezone($tz)->format($year ? 'j M Y' : 'j M');
        return '<time datetime="' . e(gmdate('c', $ts)) . '" data-fmt="' . ($year ? 'long' : 'short') . '">' . e($text) . '</time>';
    }

    public static function carShort(?string $c): string
    {
        $c ??= '';
        $s = trim(preg_replace(['/\s+GT3.*$/i', '/\s+\(.*\)$/'], '', $c) ?? $c);
        return $s !== '' ? $s : $c;
    }

    private const STOP = ['circuit', 'circuito', 'de', 'del', 'di', 'the', 'autodromo', 'autódromo', 'international', 'raceway', 'park', 'motorsport', 'speedway'];

    /** One word for tight spaces: "Circuit de Barcelona Catalunya" → "Barcelona". */
    public static function trackTiny(?string $t): string
    {
        $t ??= '';
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
