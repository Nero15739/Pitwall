<?php
/* Where a race's incidents stop counting. After the chequered flag some drivers play around on the
   cool-down lap, and those incidents get counted too. An admin sets, per race, either
     - "finish": each driver's incidents stop when that driver takes the flag (anything on a lap
       after the laps they completed in the race export), or
     - "time": every incident after a race time is left out (the race clock, which the replay
       and the live log share).
   That applies to
     - the replay harvest: its incidents (and laps) after the cut, so the heat maps, off/spin counts
       and awards ignore them;
     - the race's live log on /live: incidents after the cut are hidden;
     - official incident points, if asked: when the race's live log saw every driver's count (an
       admin's log), the points it counted after the cut come off each driver's total from the
       race export.
   Applied as the data is loaded for compiling (Compiler::run), so the stats themselves don't change. */
declare(strict_types=1);

namespace PitWall;

final class Cutoffs
{
    private const SETTING = 'incident_cutoffs';

    /** @return array<int, array{mode: string, until: ?float, official: bool, by: string, at: string}> subsession → its cut */
    public static function all(Store $store): array
    {
        $raw = json_decode($store->setting(self::SETTING, '{}'), true);
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $sub => $c) {
            if (!is_array($c)) continue;
            $mode = ($c['mode'] ?? 'time') === 'finish' ? 'finish' : 'time';
            if ($mode === 'time' && !is_numeric($c['until'] ?? null)) continue;
            $out[(int) $sub] = ['mode' => $mode, 'until' => $mode === 'time' ? (float) $c['until'] : null, 'official' => !empty($c['official']),
                'by' => (string) ($c['by'] ?? ''), 'at' => (string) ($c['at'] ?? '')];
        }
        return $out;
    }

    /** Set a race's cut ("finish", or "time" with $until), or clear it with mode null. */
    public static function set(Store $store, int $sub, ?string $mode, ?float $until, bool $official, string $actor): void
    {
        $all = self::all($store);
        if ($mode === null) unset($all[$sub]);
        else $all[$sub] = ['mode' => $mode, 'until' => $mode === 'time' ? round((float) $until, 1) : null, 'official' => $official, 'by' => $actor, 'at' => gmdate('Y-m-d\TH:i:s\Z')];
        ksort($all);
        $store->setSetting(self::SETTING, $all ? json_encode($all) : '{}');
    }

    /** A race time as typed: "28:10", "28:10.5", "1:02:03" or seconds. */
    public static function parse(string $s): float
    {
        $s = trim($s);
        if (preg_match('/^(?:(\d+):)?(\d{1,3}):(\d{1,2}(?:\.\d+)?)$/', $s, $m) && (float) $m[3] < 60) {
            return (int) ($m[1] ?: 0) * 3600 + (int) $m[2] * 60 + (float) $m[3];
        }
        if (preg_match('/^\d+(?:\.\d+)?$/', $s)) return (float) $s;
        throw new UserError($s === '' ? 'Type the race time incidents should count up to, like 28:10.' : "“{$s}” isn’t a race time. Write it as minutes:seconds, like 28:10.");
    }

    /** Race clock for showing and for the input: m:ss, with tenths when there are any. */
    public static function clock(float $t): string
    {
        $tenths = (int) round($t * 10);
        $s = intdiv($tenths, 10);
        $out = $s >= 3600 ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60) : sprintf('%d:%02d', intdiv($s, 60), $s % 60);
        return $tenths % 10 ? $out . '.' . ($tenths % 10) : $out;
    }

    /** Laps each driver completed (customer ID → laps), from a race record. */
    public static function lapsOf(?array $record): array
    {
        $laps = [];
        foreach ($record['entries'] ?? [] as $e) if (is_numeric($e['laps'] ?? null)) $laps[(int) $e['cust_id']] = (int) $e['laps'];
        return $laps;
    }

    /** Was this incident after the driver had finished: on a lap past the laps they completed? (The sim's lap counter
        reads n during lap n, and n + 1 once it's done.) */
    public static function afterFinish(array $i, array $laps): bool
    {
        $lap = $i['lap'] ?? null;
        $done = $laps[(int) ($i['cust_id'] ?? 0)] ?? null;
        return $lap !== null && $done !== null && (int) $lap > $done;
    }

    /** Does an incident (t, lap, cust_id) fall after this cut? */
    public static function isOut(array $cut, array $i, array $laps): bool
    {
        return $cut['mode'] === 'finish' ? self::afterFinish($i, $laps) : (float) $i['t'] > $cut['until'];
    }

    // ---------------------------------------------------------------- applying one
    /** A harvest without what came after the cut: its incidents, and laps begun after it. */
    public static function harvest(array $h, array $cut, array $laps): array
    {
        $ids = array_column($h['drivers'] ?? [], 'cust_id', 'car_idx');
        $h['incidents'] = array_values(array_filter($h['incidents'] ?? [], fn($i) => !self::isOut($cut, $i, $laps)));
        if (isset($h['splits']['laps'])) {
            $h['splits']['laps'] = array_values(array_filter($h['splits']['laps'], fn($l) => $cut['mode'] === 'finish'
                ? !self::afterFinish(['lap' => $l['lap'] ?? null, 'cust_id' => $l['cust_id'] ?? $ids[$l['car_idx']] ?? 0], $laps)
                : !isset($l['start']) || (float) $l['start'] <= $cut['until']));
        }
        return $h;
    }

    /** A live log as shown: when it's a race with a cut, the incidents after it move to ['cut']. */
    public static function live(array $d, array $cuts, array $laps): array
    {
        $cut = $cuts[(int) ($d['subsession'] ?? 0)] ?? null;
        if (!$cut || !self::isRace($d)) return $d;
        $keep = [];
        $late = [];
        foreach ($d['incidents'] as $e) {
            if (self::isOut($cut, $e, $laps)) $late[] = $e;
            else $keep[] = $e;
        }
        $d['incidents'] = $keep;
        $d['cut'] = $cut + ['incidents' => $late, 'points' => array_sum(array_column($late, 'delta'))];
        return $d;
    }

    /** A live log as shown, looking up its race's cut and laps in the store. */
    public static function liveFrom(Store $store, array $d, ?array $cuts = null): array
    {
        $cuts ??= self::all($store);
        $sub = (int) ($d['subsession'] ?? 0);
        if (!isset($cuts[$sub]) || !self::isRace($d)) return $d;
        $record = json_decode((string) ($store->race($sub)['processed'] ?? ''), true);
        return self::live($d, $cuts, self::lapsOf(is_array($record) ? $record : null));
    }

    public static function isRace(array $d): bool
    {
        return stripos((string) ($d['session']['type'] ?? ''), 'race') !== false;
    }

    /** Did this live log see every driver's count (an admin's log, or a session where counts are public)? */
    public static function seesEveryone(array $d): bool
    {
        return in_array($d['view'] ?? '', ['Admin', 'Everyone'], true);
    }

    /** Points the live log counted after the cut, per customer ID; null when it didn't see every driver. */
    public static function latePoints(array $d, array $cut, array $laps): ?array
    {
        if (!self::seesEveryone($d)) return null;
        $pts = [];
        foreach ($d['incidents'] as $e) {
            if (self::isOut($cut, $e, $laps)) $pts[(int) $e['cust_id']] = ($pts[(int) $e['cust_id']] ?? 0) + (int) $e['delta'];
        }
        return $pts;
    }

    /** A race record with each driver's official incident points less those counted after the cut. */
    public static function official(array $race, array $late): array
    {
        foreach ($race['entries'] as &$e) {
            if (isset($late[$e['cust_id']])) $e['inc'] = max(0, (int) $e['inc'] - $late[$e['cust_id']]);
        }
        unset($e);
        return $race;
    }

    // ---------------------------------------------------------------- for the admin page
    /** When the leader took the chequered flag, and when the last car crossed the line after that, from the harvest's timed laps. */
    public static function finish(array $h, ?int $raceLaps): array
    {
        $laps = $h['splits']['laps'] ?? [];
        $raceLaps = $raceLaps ?: (count($h['lap_order'] ?? []) ?: null);
        $leader = null;
        foreach ($laps as $l) {
            if ($raceLaps && (int) $l['lap'] === $raceLaps && isset($l['start'])) {
                $end = (float) $l['start'] + (float) $l['lap_time'];
                $leader = $leader === null ? $end : min($leader, $end);
            }
        }
        if ($leader === null) return ['laps' => $raceLaps, 'leader' => null, 'last' => null];
        // each car finishes at its first crossing of the line once the leader has
        $first = [];
        foreach ($laps as $l) {
            if (!isset($l['start'])) continue;
            foreach ([(float) $l['start'], (float) $l['start'] + (float) $l['lap_time']] as $x) {
                if ($x >= $leader - 0.01) $first[(int) $l['car_idx']] = min($first[(int) $l['car_idx']] ?? INF, $x);
            }
        }
        return ['laps' => $raceLaps, 'leader' => round($leader, 1), 'last' => round($first ? max($first) : $leader, 1)];
    }

    /**
     * What the admin page shows for one race.
     * @param array  $race     its row from Store::races(), plus its round number
     * @param ?array $record   its processed race record (entries, race_laps)
     * @param ?array $harvest  its harvest file
     * @param ?array $log      its race live log
     */
    public static function review(array $race, ?array $record, ?array $harvest, ?array $log, ?array $cut): array
    {
        $laps = self::lapsOf($record);
        $finish = $harvest ? self::finish($harvest, isset($record['race_laps']) ? (int) $record['race_laps'] : null) : ['laps' => $record['race_laps'] ?? null, 'leader' => null, 'last' => null];

        // every incident on one clock, with where it came from
        $events = [];
        foreach ($harvest['incidents'] ?? [] as $i) {
            $events[] = ['t' => (float) $i['t'], 'lap' => $i['lap'] ?? null, 'cust_id' => (int) $i['cust_id'], 'name' => $i['name'], 'ai' => (bool) $i['ai'],
                'what' => ($i['kind'] ?? ($i['off_track'] ? 'off' : 'spin')) === 'spin' ? 'Spin' : 'Off track', 'points' => null, 'from' => 'replay'];
        }
        foreach ($log['incidents'] ?? [] as $e) {
            $events[] = ['t' => (float) $e['t'], 'lap' => $e['lap'] ?? null, 'cust_id' => (int) $e['cust_id'], 'name' => $e['name'], 'ai' => (bool) $e['ai'],
                'what' => Live::KINDS[$e['kind']] ?? (string) $e['kind'], 'points' => (int) $e['delta'], 'from' => 'live log'];
        }
        usort($events, fn($a, $b) => $a['t'] <=> $b['t'] ?: strcmp($a['from'], $b['from']));
        foreach ($events as &$e) {
            $e['done'] = self::afterFinish($e, $laps);        // after that driver took the flag
            $e['out'] = $cut !== null && self::isOut($cut, $e, $laps);
        }
        unset($e);
        // the end of the race: from a little before the leader finished (or the cut-off, or the first incident after a finish)
        $firstDone = null;
        foreach ($events as $e) if ($e['done']) { $firstDone = $e['t']; break; }
        $marks = array_filter([$finish['leader'], $cut['until'] ?? null, $firstDone], fn($x) => $x !== null);
        $from = ($marks ? min($marks) : ($events ? $events[count($events) - 1]['t'] - 240 : 0)) - 90;
        $end = array_values(array_filter($events, fn($e) => $e['t'] >= $from));

        // what each way of cutting would leave out
        $count = function (array $c) use ($harvest, $log, $laps, $record) {
            $official = [];
            if ($log && ($late = self::latePoints($log, $c, $laps))) {
                foreach ($record['entries'] ?? [] as $x) {
                    if (!empty($late[$x['cust_id']])) $official[] = ['name' => $x['name'], 'ai' => $x['ai'], 'from' => (int) $x['inc'], 'to' => max(0, (int) $x['inc'] - $late[$x['cust_id']])];
                }
                usort($official, fn($a, $b) => ($b['from'] - $b['to']) <=> ($a['from'] - $a['to']) ?: strcmp($a['name'], $b['name']));
            }
            return [
                'replay' => count(array_filter($harvest['incidents'] ?? [], fn($i) => self::isOut($c, $i, $laps))),
                'live' => count(array_filter($log['incidents'] ?? [], fn($e) => self::isOut($c, $e, $laps))),
                'official' => $official,
            ];
        };
        return [
            'race' => $race, 'cut' => $cut, 'finish' => $finish, 'has_laps' => (bool) $laps,
            'harvest' => $harvest ? count($harvest['incidents']) : null,
            'log' => $log ? ['n' => count($log['incidents']), 'points' => array_sum(array_column($log['incidents'], 'delta')), 'view' => $log['view'] ?? '', 'by' => $log['recorded_by']['name'] ?? '', 'everyone' => self::seesEveryone($log)] : null,
            'events' => $end,
            'by_finish' => $laps ? $count(['mode' => 'finish', 'until' => null]) : null,
            'by_time' => $cut && $cut['mode'] === 'time' ? $count($cut) : null,
        ];
    }
}
