<?php
/* Validates and stores uploaded files. Shared by the admin panel, the API and the CLI importer,
   so a file is checked the same way whichever door it comes in through. */
declare(strict_types=1);

namespace PitWall;

use PitWall\Stats\Race;

final class Ingest
{
    /** What kind of file this is: an iRacing event result, a harvested replay, or neither. */
    public static function detect(array $doc): ?string
    {
        if (isset($doc['version'], $doc['subsession'], $doc['incidents']) && is_array($doc['incidents'])) return 'harvest';
        $ev = $doc['data'] ?? $doc;
        if (is_array($ev) && isset($ev['session_results'], $ev['subsession_id'])) return 'result';
        return null;
    }

    public static function decode(string $json): array
    {
        $max = (int) Config::get('max_upload_mb', 10) * 1024 * 1024;
        if (strlen($json) > $max) throw new UserError('The file is larger than ' . Config::get('max_upload_mb') . ' MB.', 413);
        if (str_starts_with($json, "\x1f\x8b")) {
            $json = @gzdecode($json, $max);
            if ($json === false) throw new UserError('The gzip data could not be decompressed.', 400);
        }
        $json = preg_replace('/^\xEF\xBB\xBF/', '', $json) ?? $json; // BOM from some editors
        try {
            $doc = json_decode($json, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $e) {
            throw new UserError('That isn’t valid JSON: ' . $e->getMessage() . '.', 400);
        }
        if (!is_array($doc)) throw new UserError('Expected a JSON object.', 400);
        return $doc;
    }

    /** Store any supported file, whatever it is. */
    public static function any(Store $store, string $json, ?string $season, string $filename, string $actor): array
    {
        $doc = self::decode($json);
        return match (self::detect($doc)) {
            'result' => self::result($store, $doc, $json, $season, $filename, $actor),
            'harvest' => self::harvest($store, $doc, $json, $filename, $actor),
            default => throw new UserError("{$filename}: not an iRacing event result or a harvested replay file."),
        };
    }

    /**
     * An iRacing event result export (eventresult-*.json).
     * Season: the one asked for; else the race's current season when re-uploading; else the season
     * holding other races from the same league season; else the league season's own name.
     */
    public static function result(Store $store, array $doc, string $json, ?string $season, string $filename, string $actor): array
    {
        try {
            $race = Race::process($doc, $filename);
        } catch (UserError $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new UserError("{$filename}: couldn’t read this event result (" . $e->getMessage() . ').');
        }
        $sub = (int) $race['subsession'];
        if ($sub <= 0) throw new UserError("{$filename}: the subsession ID is missing.");
        $existing = $store->race($sub);
        $sha = sha1($json);

        $season = trim((string) $season);
        if ($season !== '') $s = $store->ensureSeason($season);
        elseif ($existing) $s = $store->season((int) $existing['season_id']);
        elseif ($race['league_season_id'] && ($found = $store->seasonForLeague((int) $race['league_season_id']))) $s = $found;
        elseif ($race['league']) $s = $store->ensureSeason($race['league']);
        else throw new UserError("{$filename}: this isn’t a league race, so pick a season for it.");

        $summary = [
            'type' => 'result', 'subsession' => $sub, 'season' => $s['name'], 'season_slug' => $s['slug'],
            'track' => $race['track'], 'date' => $race['date'], 'drivers' => count($race['entries']),
        ];
        if ($existing && $existing['sha1'] === $sha && (int) $existing['season_id'] === (int) $s['id']) {
            return $summary + ['status' => 'unchanged'];
        }
        $created = $store->saveRace([
            'subsession' => $sub,
            'season_id' => (int) $s['id'],
            'league_season_id' => $race['league_season_id'] ? (int) $race['league_season_id'] : null,
            'track_id' => (int) $race['track_id'],
            'track' => mb_substr($race['track'], 0, 200),
            'start_time' => (string) $race['date'],
            'filename' => mb_substr(basename($filename), 0, 255),
            'sha1' => $sha,
            'bytes' => strlen($json),
            'raw' => Store::encodeRaw($json),
            'processed' => json_encode($race, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'process_version' => Race::VERSION,
            'uploaded_at' => Store::now(),
            'uploaded_by' => $actor,
        ]);
        $store->log($actor, $created ? 'race.add' : 'race.replace', "{$sub} {$race['track']} → {$s['name']}");
        return $summary + ['status' => $created ? 'created' : 'replaced'];
    }

    /** A harvested replay (incidents-<subsession>.json from the local harvester). */
    public static function harvest(Store $store, array $doc, string $json, string $filename, string $actor): array
    {
        self::checkHarvest($doc, $filename);
        $sub = (int) $doc['subsession'];
        $sha = sha1($json);
        $race = $store->race($sub);
        $summary = [
            'type' => 'harvest', 'subsession' => $sub, 'track' => $doc['track']['name'] ?? null,
            'incidents' => count($doc['incidents']), 'laps' => count($doc['splits']['laps'] ?? []),
            'matched' => (bool) $race, 'season' => $race['season'] ?? null,
        ];
        if ($store->harvestSha($sub) === $sha) return $summary + ['status' => 'unchanged'];
        $created = $store->saveHarvest([
            'subsession' => $sub,
            'track_id' => isset($doc['track']['id']) ? (int) $doc['track']['id'] : null,
            'track' => isset($doc['track']['name']) ? mb_substr((string) $doc['track']['name'], 0, 200) : null,
            'harvested_at' => isset($doc['harvested_at']) ? mb_substr((string) $doc['harvested_at'], 0, 30) : null,
            'n_incidents' => count($doc['incidents']),
            'n_laps' => count($doc['splits']['laps'] ?? []),
            'filename' => mb_substr(basename($filename), 0, 255),
            'sha1' => $sha,
            'bytes' => strlen($json),
            'raw' => Store::encodeRaw($json),
            'uploaded_at' => Store::now(),
            'uploaded_by' => $actor,
        ]);
        $store->log($actor, $created ? 'harvest.add' : 'harvest.replace', "{$sub} " . ($doc['track']['name'] ?? '') . ' · ' . count($doc['incidents']) . ' incidents');
        return $summary + ['status' => $created ? 'created' : 'replaced'];
    }

    /** Shape check, so a bad file is refused at the door instead of breaking a compile later. */
    private static function checkHarvest(array $d, string $filename): void
    {
        $bad = fn(string $why) => new UserError("{$filename}: not a usable harvest file ({$why}).");
        if (($d['version'] ?? null) !== 1) throw $bad('expected "version": 1');
        if (!is_int($d['subsession'] ?? null) || $d['subsession'] <= 0) throw $bad('missing subsession');
        if (!is_array($d['track'] ?? null)) throw $bad('missing track');
        $num = fn($v) => is_int($v) || is_float($v);
        foreach ($d['incidents'] as $k => $i) {
            if (!is_array($i) || !$num($i['pct'] ?? null) || !$num($i['t'] ?? null) || !$num($i['lap'] ?? null)
                || !isset($i['cust_id'], $i['name']) || !array_key_exists('off_track', $i)) {
                throw $bad("incident {$k} is incomplete");
            }
        }
        if (isset($d['splits'])) {
            $sp = $d['splits'];
            if (!is_array($sp['sectors'] ?? null) || !is_array($sp['laps'] ?? null) || !$sp['sectors']) throw $bad('splits need sectors and laps');
            foreach ($sp['sectors'] as $s) if (!$num($s)) throw $bad('sector starts must be numbers');
            foreach ($sp['laps'] as $k => $l) {
                if (!is_array($l) || !isset($l['cust_id']) || !is_array($l['sectors'] ?? null) || !$num($l['lap_time'] ?? null) || !array_key_exists('pit', $l)) {
                    throw $bad("split lap {$k} is incomplete");
                }
                foreach ($l['sectors'] as $s) if (!$num($s)) throw $bad("split lap {$k} has a non-numeric sector");
            }
        }
    }
}
