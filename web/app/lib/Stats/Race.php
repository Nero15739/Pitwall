<?php
/* One exported race (iRacing eventresult JSON) → a compact race record. */
declare(strict_types=1);

namespace PitWall\Stats;

use PitWall\Num;
use RuntimeException;

final class Race
{
    /** Bump when the record changes shape: stored records are reprocessed from the raw export. */
    public const VERSION = 1;

    public static function process(array $raw, string $file): array
    {
        $ev = $raw['data'] ?? $raw;
        if (!is_array($ev['session_results'] ?? null)) throw new RuntimeException('not an iRacing event result (no session_results)');
        $sessions = [];
        foreach ($ev['session_results'] as $s) $sessions[(int) ($s['simsession_number'] ?? -99)] = $s;
        $race = $sessions[0] ?? null;
        if (!$race) throw new RuntimeException('no race session (simsession 0)');
        if (!isset($ev['subsession_id'], $ev['track']['track_id'], $ev['start_time'])) {
            throw new RuntimeException('missing subsession_id, track or start_time');
        }

        $bests = function (int $num) use ($sessions): array {
            $m = [];
            foreach ($sessions[$num]['results'] ?? [] as $r) $m[$r['cust_id']] = Num::secs($r['best_lap_time'] ?? null);
            return $m;
        };
        $prac = $bests(-2);
        $qual = $bests(-1);
        // qualifying rank across the whole field
        $q = [];
        foreach ($qual as $cid => $t) if ($t) $q[] = [$cid, $t];
        usort($q, fn($a, $b) => $a[1] <=> $b[1]);
        $qualRank = [];
        foreach ($q as $i => [$cid]) $qualRank[$cid] = $i + 1;

        $entries = [];
        foreach ($race['results'] as $r) {
            $cid = $r['cust_id'];
            $points = array_key_exists('league_points', $r) ? $r['league_points'] : ($r['champ_points'] ?? 0);
            $interval = $r['interval'] ?? null;
            $entries[] = [
                'cust_id' => $cid,
                'name' => (string) $r['display_name'],
                'ai' => (bool) ($r['ai'] ?? false),
                'car' => (string) $r['car_name'],
                'car_id' => $r['car_id'] ?? null,
                'start' => $r['starting_position'] + 1,
                'finish' => $r['finish_position'] + 1,
                'laps' => $r['laps_complete'],
                'laps_led' => $r['laps_lead'],
                'best' => Num::secs($r['best_lap_time'] ?? null),
                'best_lap_num' => $r['best_lap_num'],
                'avg' => Num::secs($r['average_lap'] ?? null),
                'inc' => $r['incidents'],
                'points' => $points ?: 0,
                'status' => $r['reason_out'] ?? '',
                'interval' => is_numeric($interval) && $interval >= 0 ? Num::secs($interval) : null,
                'qual' => $qual[$cid] ?? null,
                'qual_rank' => $qualRank[$cid] ?? null,
                'prac' => $prac[$cid] ?? null,
                'helmet' => $r['helmet']['color1'] ?? null,
            ];
        }
        usort($entries, fn($a, $b) => $a['finish'] <=> $b['finish']);

        $track = $ev['track'];
        $cfg = $track['config_name'] ?? '';
        $w = $ev['weather'] ?? [];
        return [
            'file' => $file,
            'subsession' => $ev['subsession_id'],
            'date' => $ev['start_time'],
            'track' => $track['track_name'] . ($cfg && $cfg !== 'N/A' ? " – {$cfg}" : ''),
            'track_short' => $track['track_name'],
            'track_id' => $track['track_id'],
            'league' => ($ev['league_season_name'] ?? '') ?: (($ev['league_name'] ?? '') ?: null),
            'league_season_id' => $ev['league_season_id'] ?? null,
            'sof' => $ev['event_strength_of_field'] ?? null,
            'race_laps' => $ev['event_laps_complete'] ?? null,
            'cautions' => $ev['num_cautions'] ?? 0,
            'lead_changes' => $ev['num_lead_changes'] ?? 0,
            'corners' => $ev['corners_per_lap'] ?? null,
            'temp_f' => ($w['temp_units'] ?? null) === 0 ? ($w['temp_value'] ?? null) : null,
            'entries' => $entries,
        ];
    }
}
