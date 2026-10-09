<?php
/* Everything the site keeps in its database: seasons, race exports, harvested replays, track
   maps, settings, the admin account, API keys, rate limits and the activity log. */
declare(strict_types=1);

namespace PitWall;

final class Store
{
    /** Season slugs that would collide with the site's own URLs. */
    public const RESERVED = ['admin', 'api', 'assets', 'storage', 'app', 'bin', 'data', 'favicon.svg', 'robots.txt'];

    public function __construct(private readonly Db $db) {}

    public static function open(): self
    {
        return new self(Db::get());
    }

    public function db(): Db { return $this->db; }

    public static function now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }

    public static function encodeRaw(string $json): string
    {
        return base64_encode(gzencode($json, 6));
    }

    public static function decodeRaw(string $stored): array
    {
        $json = gzdecode(base64_decode($stored, true) ?: '');
        if ($json === false) throw new \RuntimeException('stored file is damaged');
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    // ---------------------------------------------------------------- seasons
    public function seasons(): array
    {
        return $this->db->all(
            'SELECT s.id, s.name, s.slug, s.created_at, COUNT(r.subsession) AS races,
                    MIN(r.start_time) AS first_race, MAX(r.start_time) AS last_race
             FROM seasons s LEFT JOIN races r ON r.season_id = s.id
             GROUP BY s.id, s.name, s.slug, s.created_at
             ORDER BY last_race DESC, s.name');
    }

    public function season(int $id): ?array
    {
        return $this->db->one('SELECT * FROM seasons WHERE id = ?', [$id]);
    }

    public static function checkSeasonName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '' || mb_strlen($name) > 80) throw new UserError('A season name needs 1 to 80 characters.');
        $slug = Num::slug($name);
        if ($slug === '') throw new UserError('A season name needs at least one letter or number.');
        if (in_array($slug, self::RESERVED, true)) throw new UserError("“{$name}” is reserved by the site. Pick another season name.");
        return $name;
    }

    /** The season with this name (or the same URL slug), created if needed. */
    public function ensureSeason(string $name): array
    {
        $name = self::checkSeasonName($name);
        $slug = Num::slug($name);
        $s = $this->db->one('SELECT * FROM seasons WHERE slug = ?', [$slug]);
        if ($s) return $s;
        $id = $this->db->insert('seasons', ['name' => $name, 'slug' => $slug, 'created_at' => self::now()]);
        return $this->season($id);
    }

    public function renameSeason(int $id, string $name): void
    {
        $name = self::checkSeasonName($name);
        $slug = Num::slug($name);
        $clash = $this->db->val('SELECT id FROM seasons WHERE slug = ? AND id <> ?', [$slug, $id]);
        if ($clash) throw new UserError('Another season already uses that name.');
        $this->db->update('seasons', ['name' => $name, 'slug' => $slug], 'id = :id', ['id' => $id]);
    }

    public function deleteSeason(int $id): void
    {
        if ((int) $this->db->val('SELECT COUNT(*) FROM races WHERE season_id = ?', [$id]) > 0) {
            throw new UserError('Move or delete its races first.');
        }
        $this->db->run('DELETE FROM seasons WHERE id = ?', [$id]);
    }

    // ---------------------------------------------------------------- races
    public function races(): array
    {
        return $this->db->all(
            'SELECT r.subsession, r.season_id, s.name AS season, s.slug AS season_slug, r.league_season_id, r.track_id, r.track,
                    r.start_time, r.filename, r.bytes, r.uploaded_at, r.uploaded_by,
                    CASE WHEN h.subsession IS NULL THEN 0 ELSE 1 END AS harvested, h.n_incidents, h.n_laps
             FROM races r JOIN seasons s ON s.id = r.season_id LEFT JOIN harvests h ON h.subsession = r.subsession
             ORDER BY r.start_time DESC');
    }

    public function race(int $sub): ?array
    {
        return $this->db->one('SELECT r.*, s.name AS season FROM races r JOIN seasons s ON s.id = r.season_id WHERE r.subsession = ?', [$sub]);
    }

    public function racesForCompile(): array
    {
        $rows = $this->db->all(
            'SELECT r.subsession, s.name AS season, r.filename, r.processed, r.process_version
             FROM races r JOIN seasons s ON s.id = r.season_id ORDER BY r.start_time');
        foreach ($rows as &$r) $r['process_version'] = (int) $r['process_version'];
        return $rows;
    }

    public function raceRaw(int $sub): string
    {
        return (string) $this->db->val('SELECT raw FROM races WHERE subsession = ?', [$sub]);
    }

    public function seasonForLeague(int $leagueSeasonId): ?array
    {
        return $this->db->one(
            'SELECT s.* FROM seasons s JOIN races r ON r.season_id = s.id WHERE r.league_season_id = ? ORDER BY r.start_time DESC LIMIT 1',
            [$leagueSeasonId]);
    }

    /** @return bool true when created, false when replaced */
    public function saveRace(array $row): bool
    {
        return $this->db->tx(function (Db $db) use ($row) {
            $exists = $db->val('SELECT 1 FROM races WHERE subsession = ?', [$row['subsession']]);
            if ($exists) {
                $sub = $row['subsession'];
                unset($row['subsession']);
                $db->update('races', $row, 'subsession = :sub', ['sub' => $sub]);
                return false;
            }
            $db->insert('races', $row);
            return true;
        });
    }

    public function updateProcessed(int $sub, array $race): void
    {
        $this->db->update('races', ['processed' => json_encode($race, JSON_UNESCAPED_UNICODE), 'process_version' => Stats\Race::VERSION],
            'subsession = :sub', ['sub' => $sub]);
    }

    public function moveRace(int $sub, int $seasonId): void
    {
        if (!$this->season($seasonId)) throw new UserError('That season no longer exists.');
        $this->db->update('races', ['season_id' => $seasonId], 'subsession = :sub', ['sub' => $sub]);
    }

    public function deleteRace(int $sub): void
    {
        $this->db->run('DELETE FROM races WHERE subsession = ?', [$sub]);
    }

    // ---------------------------------------------------------------- harvested replays
    public function harvests(): array
    {
        return $this->db->all(
            'SELECT h.subsession, h.track_id, h.track, h.harvested_at, h.n_incidents, h.n_laps, h.filename, h.bytes,
                    h.uploaded_at, h.uploaded_by, CASE WHEN r.subsession IS NULL THEN 0 ELSE 1 END AS matched
             FROM harvests h LEFT JOIN races r ON r.subsession = h.subsession ORDER BY h.uploaded_at DESC');
    }

    public function harvestsForCompile(): array
    {
        return $this->db->all('SELECT h.subsession, h.raw FROM harvests h JOIN races r ON r.subsession = h.subsession');
    }

    public function harvestSha(int $sub): ?string
    {
        $v = $this->db->val('SELECT sha1 FROM harvests WHERE subsession = ?', [$sub]);
        return $v === null ? null : (string) $v;
    }

    public function harvestRaw(int $sub): ?string
    {
        $v = $this->db->val('SELECT raw FROM harvests WHERE subsession = ?', [$sub]);
        return $v === null ? null : (string) $v;
    }

    public function saveHarvest(array $row): bool
    {
        return $this->db->tx(function (Db $db) use ($row) {
            $exists = $db->val('SELECT 1 FROM harvests WHERE subsession = ?', [$row['subsession']]);
            if ($exists) {
                $sub = $row['subsession'];
                unset($row['subsession']);
                $db->update('harvests', $row, 'subsession = :sub', ['sub' => $sub]);
                return false;
            }
            $db->insert('harvests', $row);
            return true;
        });
    }

    public function deleteHarvest(int $sub): void
    {
        $this->db->run('DELETE FROM harvests WHERE subsession = ?', [$sub]);
    }

    // ---------------------------------------------------------------- track maps (cache for TrackMaps)
    public function trackMap(int $id): ?array
    {
        $r = $this->db->one('SELECT map, error, fetched_at FROM track_maps WHERE track_id = ?', [$id]);
        if (!$r) return null;
        return ['fetched' => $r['fetched_at'], 'map' => $r['map'] ? json_decode($r['map'], true) : null, 'error' => $r['error']];
    }

    public function saveTrackMap(int $id, array $entry): void
    {
        $row = ['map' => $entry['map'] ? json_encode($entry['map'], JSON_UNESCAPED_SLASHES) : null, 'error' => $entry['error'] ?? null, 'fetched_at' => $entry['fetched']];
        $this->db->tx(function (Db $db) use ($id, $row) {
            if ($db->val('SELECT 1 FROM track_maps WHERE track_id = ?', [$id])) $db->update('track_maps', $row, 'track_id = :id', ['id' => $id]);
            else $db->insert('track_maps', $row + ['track_id' => $id]);
        });
    }

    public function forgetTrackMaps(): void
    {
        $this->db->run('DELETE FROM track_maps');
    }

    // ---------------------------------------------------------------- settings
    public function setting(string $name, string $default = ''): string
    {
        $v = $this->db->val('SELECT value FROM settings WHERE name = ?', [$name]);
        return $v === null ? $default : (string) $v;
    }

    public function setSetting(string $name, string $value): void
    {
        $this->db->tx(function (Db $db) use ($name, $value) {
            if ($db->val('SELECT 1 FROM settings WHERE name = ?', [$name])) $db->update('settings', ['value' => $value], 'name = :n', ['n' => $name]);
            else $db->insert('settings', ['name' => $name, 'value' => $value]);
        });
    }

    // ---------------------------------------------------------------- admin account
    public function userCount(): int
    {
        return (int) $this->db->val('SELECT COUNT(*) FROM users');
    }

    public function user(string $username): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE username = ?', [$username]);
    }

    public function userById(int $id): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public function createUser(string $username, string $password): int
    {
        return $this->db->insert('users', [
            'username' => $username, 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'created_at' => self::now(),
        ]);
    }

    public function setPassword(int $id, string $password): void
    {
        $this->db->update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = :id', ['id' => $id]);
    }

    public function setUsername(int $id, string $username): void
    {
        $this->db->update('users', ['username' => $username], 'id = :id', ['id' => $id]);
    }

    public function touchLogin(int $id, ?string $rehash = null): void
    {
        $row = ['last_login_at' => self::now()];
        if ($rehash) $row['password_hash'] = $rehash;
        $this->db->update('users', $row, 'id = :id', ['id' => $id]);
    }

    // ---------------------------------------------------------------- API keys (only a hash is stored)
    public function apiKeys(): array
    {
        return $this->db->all('SELECT id, name, prefix, created_at, last_used_at, last_ip, uses, revoked_at FROM api_keys ORDER BY revoked_at IS NOT NULL, created_at DESC');
    }

    /** @return string the new key, shown once */
    public function createApiKey(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) throw new UserError('Give the key a name (up to 100 characters), like “Harvester PC”.');
        $key = 'pw_' . rtrim(strtr(base64_encode(random_bytes(30)), '+/', '-_'), '=');
        $this->db->insert('api_keys', [
            'name' => $name, 'prefix' => substr($key, 0, 10), 'hash' => hash('sha256', $key), 'created_at' => self::now(),
        ]);
        return $key;
    }

    public function revokeApiKey(int $id): void
    {
        $this->db->run('UPDATE api_keys SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL', [self::now(), $id]);
    }

    public function deleteApiKey(int $id): void
    {
        $this->db->run('DELETE FROM api_keys WHERE id = ? AND revoked_at IS NOT NULL', [$id]);
    }

    public function apiKeyFor(string $token): ?array
    {
        if (!preg_match('/^pw_[A-Za-z0-9_-]{20,}$/', $token)) return null;
        return $this->db->one('SELECT * FROM api_keys WHERE hash = ? AND revoked_at IS NULL', [hash('sha256', $token)]);
    }

    public function touchApiKey(int $id, string $ip): void
    {
        $this->db->run('UPDATE api_keys SET last_used_at = ?, last_ip = ?, uses = uses + 1 WHERE id = ?', [self::now(), $ip, $id]);
    }

    // ---------------------------------------------------------------- rate limits
    /** Count a hit against a fixed window. @return array{0: bool, 1: int} allowed, seconds until reset */
    public function throttle(string $key, int $limit, int $window): array
    {
        $now = time();
        return $this->db->tx(function (Db $db) use ($key, $limit, $window, $now) {
            $r = $db->one('SELECT hits, reset_at FROM throttle WHERE k = ?', [$key]);
            if (!$r || (int) $r['reset_at'] <= $now) {
                if ($r) $db->update('throttle', ['hits' => 1, 'reset_at' => $now + $window], 'k = :k', ['k' => $key]);
                else $db->insert('throttle', ['k' => $key, 'hits' => 1, 'reset_at' => $now + $window]);
                return [true, $window];
            }
            $hits = (int) $r['hits'] + 1;
            $db->update('throttle', ['hits' => $hits], 'k = :k', ['k' => $key]);
            return [$hits <= $limit, (int) $r['reset_at'] - $now];
        });
    }

    /** True while a key is over its limit, without counting a hit. */
    public function throttled(string $key, int $limit): bool
    {
        $r = $this->db->one('SELECT hits, reset_at FROM throttle WHERE k = ?', [$key]);
        return $r && (int) $r['reset_at'] > time() && (int) $r['hits'] >= $limit;
    }

    public function clearThrottle(string $key): void
    {
        $this->db->run('DELETE FROM throttle WHERE k = ?', [$key]);
    }

    // ---------------------------------------------------------------- activity log
    public function log(string $actor, string $action, string $detail = '', ?string $ip = null): void
    {
        $this->db->insert('audit_log', ['at' => self::now(), 'actor' => $actor, 'action' => $action, 'detail' => mb_substr($detail, 0, 2000), 'ip' => $ip]);
        if (random_int(1, 50) === 1) {
            $this->db->run('DELETE FROM audit_log WHERE at < ?', [gmdate('Y-m-d\TH:i:s\Z', time() - 365 * 86400)]);
            $this->db->run('DELETE FROM throttle WHERE reset_at < ?', [time() - 3600]);
        }
    }

    public function activity(int $limit = 30): array
    {
        return $this->db->all('SELECT * FROM audit_log ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)));
    }
}
