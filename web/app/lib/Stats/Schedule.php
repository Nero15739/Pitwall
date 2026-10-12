<?php
/* The season calendar: rounds already raced plus the rounds still to come from an uploaded
   schedule file. A scheduled round counts as raced once a result at that track, within three
   weeks of its date, exists. */
declare(strict_types=1);

namespace PitWall\Stats;

final class Schedule
{
    public const MAX_ROUNDS = 60;
    /** A result this far from its scheduled date is a different visit to the same track. */
    private const MATCH_WINDOW_S = 21 * 86400;
    private const WHEN = '/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2}))?(?:\.\d+)?(Z|[+-]\d{2}:?\d{2}))?$/';

    /** "2026-11-02" stays a date; "2026-11-02T19:30:00+11:00" becomes UTC. Null when it isn't either. */
    public static function when(string $s): ?string
    {
        if (!preg_match(self::WHEN, trim($s), $m)) return null;
        [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        if (!checkdate($mo, $d, $y)) return null;
        if (!isset($m[4]) || $m[4] === '') return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        [$h, $mi, $sec] = [(int) $m[4], (int) $m[5], (int) ($m[6] ?? 0)];
        if ($h > 23 || $mi > 59 || $sec > 59) return null;
        $off = 0;
        if ($m[7] !== 'Z') {
            $digits = str_replace(':', '', substr($m[7], 1));
            $off = ((int) substr($digits, 0, 2) * 3600 + (int) substr($digits, 2, 2) * 60) * ($m[7][0] === '-' ? -1 : 1);
        }
        return gmdate('Y-m-d\TH:i:s\Z', gmmktime($h, $mi, $sec, $mo, $d, $y) - $off);
    }

    /** Letters and digits only, lower case: how track names are compared. */
    public static function key(string $s): string
    {
        return mb_strtolower(preg_replace('/[^\p{L}\p{N}]/u', '', $s) ?? $s, 'UTF-8');
    }

    private static function text(mixed $v, int $max = 200): ?string
    {
        if (!is_string($v)) return null;
        $s = trim($v);
        return $s === '' ? null : mb_substr($s, 0, $max);
    }

    /** A whole number above zero (JSON may carry it as 12, 12.0 or "12"), else null. */
    private static function whole(mixed $v): ?int
    {
        $ok = is_int($v) || (is_float($v) && floor($v) === $v) || (is_string($v) && ctype_digit($v));
        return $ok && (int) $v > 0 ? (int) $v : null;
    }

    /**
     * A schedule file's rounds → clean entries in date order.
     * @throws \InvalidArgumentException naming the round that can't be used
     */
    public static function normalize(array $rounds): array
    {
        if (count($rounds) > self::MAX_ROUNDS) throw new \InvalidArgumentException('more than ' . self::MAX_ROUNDS . ' rounds');
        $out = [];
        foreach (array_values($rounds) as $i => $r) {
            $n = $i + 1;
            if (!is_array($r)) throw new \InvalidArgumentException("round {$n} is not an object");
            $track = self::text($r['track'] ?? null);
            if ($track === null) throw new \InvalidArgumentException("round {$n} needs a \"track\" name");
            $date = is_string($r['date'] ?? null) ? self::when($r['date']) : null;
            if ($date === null) {
                throw new \InvalidArgumentException("round {$n} needs a \"date\" like 2026-11-02, or with a time and UTC offset like 2026-11-02T19:30:00+11:00");
            }
            $config = self::text($r['config'] ?? null);
            $out[] = [
                'date' => $date,
                'track' => $track,
                'config' => $config === null || strcasecmp($config, 'N/A') === 0 ? null : $config,
                'track_id' => self::whole($r['track_id'] ?? null),
                'laps' => self::whole($r['laps'] ?? null),
                'minutes' => self::whole($r['minutes'] ?? null),
                'cars' => self::text($r['cars'] ?? null),
                'note' => self::text($r['note'] ?? null),
            ];
        }
        usort($out, fn($a, $b) => strcmp($a['date'], $b['date']) ?: strcmp($a['track'], $b['track']));
        return $out;
    }

    /**
     * @param array $rounds  the season's raced rounds
     * @param array $sched   normalized schedule entries (may be empty)
     * @param array $known   track key → iRacing track id, from every race the site holds
     */
    public static function calendar(array $rounds, array $sched, array $known): array
    {
        $full = fn(array $e) => $e['track'] . ($e['config'] !== null ? " – {$e['config']}" : '');

        // pair each scheduled round with the nearest unclaimed result at the same track
        $claimed = [];
        $matched = [];
        foreach ($sched as $i => $e) {
            $best = null;
            foreach ($rounds as $k => $rd) {
                if (isset($claimed[$k])) continue;
                $same = $e['track_id'] !== null
                    ? $rd['track_id'] === $e['track_id']
                    : self::key($rd['track_short']) === self::key($e['track']) && ($e['config'] === null || self::key($rd['track']) === self::key($full($e)));
                if (!$same) continue;
                $diff = abs(strtotime($rd['date']) - strtotime($e['date']));
                if ($diff <= self::MATCH_WINDOW_S && ($best === null || $diff < $best[1])) $best = [$k, $diff];
            }
            if ($best !== null) {
                $claimed[$best[0]] = $i;
                $matched[$i] = true;
            }
        }

        $cal = [];
        foreach ($rounds as $k => $rd) {
            $e = isset($claimed[$k]) ? $sched[$claimed[$k]] : null;
            $cal[] = [
                'n' => $rd['round'], 'status' => 'done', 'round' => $rd['round'], 'date' => $rd['date'],
                'track' => $rd['track'], 'track_short' => $rd['track_short'], 'track_id' => $rd['track_id'],
                'laps' => $rd['laps'], 'minutes' => null, 'cars' => null, 'note' => $e['note'] ?? null,
            ];
        }
        $n = count($rounds);
        foreach ($sched as $i => $e) {
            if (isset($matched[$i])) continue;
            $cal[] = [
                'n' => ++$n, 'status' => 'upcoming', 'round' => null, 'date' => $e['date'],
                'track' => $full($e), 'track_short' => $e['track'],
                'track_id' => $e['track_id'] ?? $known[self::key($full($e))] ?? $known[self::key($e['track'])] ?? null,
                'laps' => $e['laps'], 'minutes' => $e['minutes'], 'cars' => $e['cars'], 'note' => $e['note'],
            ];
        }
        return $cal;
    }

    /** Track key → id for every track in these races; the lowest id wins a clash between layouts. */
    public static function knownTracks(array $races): array
    {
        $known = [];
        foreach ($races as $r) {
            foreach ([self::key($r['track']), self::key($r['track_short'])] as $key) {
                $known[$key] = min($known[$key] ?? PHP_INT_MAX, $r['track_id']);
            }
        }
        return $known;
    }
}
