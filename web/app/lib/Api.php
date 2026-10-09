<?php
/* JSON API for pushing data from the harvester PC (or anything else that holds an API key).

   Public
     GET  /api/v1/health                 {ok, version, generated}
     GET  /api/v1/version                {generated}: changes whenever new data is published
   With "Authorization: Bearer <key>" (or "X-API-Key: <key>")
     GET  /api/v1/status                 seasons, races, and which replays still need harvesting
     POST /api/v1/results?season=Name    body: an iRacing eventresult JSON (gzip allowed)
     POST /api/v1/incidents              body: a harvested replay file (incidents-<subsession>.json)
     POST /api/v1/compile                rebuild the published data
   Uploads recompile straight away; add ?compile=0 when sending a batch, then POST /compile once. */
declare(strict_types=1);

namespace PitWall;

final class Api
{
    private const AUTH_FAILS = 20;      // bad keys per address before a 10-minute pause
    private const AUTH_WINDOW = 600;

    public static function handle(string $path, string $method): void
    {
        $route = rtrim($path, '/') ?: '/';
        try {
            match (true) {
                $route === '/v1/health' => self::only($method, 'GET') ?? Http::json([
                    'ok' => true, 'version' => PITWALL_VERSION, 'generated' => Data::index()['generated'] ?? null]),
                $route === '/v1/version' => self::only($method, 'GET') ?? Http::json(['generated' => Data::index()['generated'] ?? null]),
                $route === '/v1/status' => self::only($method, 'GET') ?? self::status(self::auth()),
                $route === '/v1/results' => self::only($method, 'POST') ?? self::upload(self::auth(), 'result'),
                $route === '/v1/incidents' => self::only($method, 'POST') ?? self::upload(self::auth(), 'harvest'),
                $route === '/v1/compile' => self::only($method, 'POST') ?? self::compile(self::auth()),
                default => self::fail(404, 'not_found', 'No such endpoint. See /api/v1/health.'),
            };
        } catch (UserError $e) {
            self::fail($e->status, $e->status === 413 ? 'too_large' : 'invalid', $e->getMessage());
        }
    }

    private static function only(string $method, string $want): ?bool
    {
        if ($method === $want || ($want === 'GET' && $method === 'HEAD')) return null;
        self::fail(405, 'method_not_allowed', "Use {$want}.", ['Allow' => $want]);
    }

    private static function fail(int $status, string $code, string $message, array $headers = []): never
    {
        Http::json(['error' => ['code' => $code, 'message' => $message]], $status, $headers);
    }

    /** @return array{0: Store, 1: array} the store and the key that signed the request */
    private static function auth(): array
    {
        $store = Store::open();
        $ip = Http::ip();
        if ($store->throttled("apifail:{$ip}", self::AUTH_FAILS)) {
            self::fail(429, 'rate_limited', 'Too many requests with a bad key. Wait ten minutes.', ['Retry-After' => (string) self::AUTH_WINDOW]);
        }
        $token = '';
        $auth = Http::header('Authorization') ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/^Bearer\s+(\S+)$/i', (string) $auth, $m)) $token = $m[1];
        $token = $token ?: trim((string) Http::header('X-API-Key'));
        $key = $token !== '' ? $store->apiKeyFor($token) : null;
        if (!$key) {
            $store->throttle("apifail:{$ip}", self::AUTH_FAILS, self::AUTH_WINDOW);
            self::fail(401, 'unauthorized', $token === '' ? 'Send your API key as "Authorization: Bearer <key>".' : 'That API key is not valid or has been revoked.',
                ['WWW-Authenticate' => 'Bearer']);
        }
        $limit = (int) Config::get('api_rate_limit', 120);
        [$ok, $reset] = $store->throttle("apikey:{$key['id']}", $limit, 60);
        if (!$ok) self::fail(429, 'rate_limited', "More than {$limit} requests a minute with this key.", ['Retry-After' => (string) $reset]);
        $store->touchApiKey((int) $key['id'], $ip);
        return [$store, $key];
    }

    private static function actor(array $key): string
    {
        return 'key:' . $key['name'];
    }

    private static function status(array $auth): never
    {
        [$store] = $auth;
        $races = $store->races();
        $harvested = [];
        foreach ($store->harvests() as $h) $harvested[(int) $h['subsession']] = $h;
        $seasons = [];
        foreach ($store->seasons() as $s) $seasons[$s['id']] = ['name' => $s['name'], 'slug' => $s['slug'], 'races' => []];
        foreach (array_reverse($races) as $r) {
            $seasons[$r['season_id']]['races'][] = [
                'subsession' => (int) $r['subsession'], 'track' => $r['track'], 'date' => $r['start_time'],
                'harvested' => isset($harvested[(int) $r['subsession']]), 'uploaded_at' => $r['uploaded_at'],
            ];
        }
        $pending = [];
        foreach ($seasons as $s) foreach ($s['races'] as $i => $r) {
            if (!$r['harvested']) $pending[] = ['season' => $s['name'], 'round' => $i + 1, 'track' => $r['track'], 'subsession' => $r['subsession']];
        }
        Http::json([
            'generated' => Data::index()['generated'] ?? null,
            'seasons' => array_values($seasons),
            'pending_harvests' => $pending,
            'unmatched_harvests' => array_values(array_map(fn($h) => (int) $h['subsession'], array_filter($harvested, fn($h) => !$h['matched']))),
        ]);
    }

    private static function upload(array $auth, string $type): never
    {
        [$store, $key] = $auth;
        $max = (int) Config::get('max_upload_mb', 10) * 1024 * 1024;
        $body = file_get_contents('php://input', false, null, 0, $max + 1);
        if ($body === false || $body === '') throw new UserError('The request body is empty. Send the JSON file as the body.', 400);
        if (stripos((string) Http::header('Content-Encoding'), 'gzip') !== false) {
            $body = @gzdecode($body, $max + 1); // capped, so a small bomb can't expand into gigabytes
            if ($body === false) throw new UserError('Content-Encoding says gzip but the body could not be decompressed (or it is too large).', 400);
        }
        $doc = Ingest::decode($body);
        $kind = Ingest::detect($doc);
        if ($kind !== $type) {
            throw new UserError($type === 'result'
                ? 'This endpoint takes an iRacing event result export. Send harvested replays to /api/v1/incidents.'
                : 'This endpoint takes a harvested replay file. Send event results to /api/v1/results.');
        }
        $filename = Http::query('filename') ?? Http::header('X-Filename') ?? ($type === 'result' ? 'eventresult-api.json' : 'incidents-api.json');
        $season = Http::query('season') ?? Http::header('X-Season');
        $r = $type === 'result'
            ? Ingest::result($store, $doc, $body, $season, $filename, self::actor($key))
            : Ingest::harvest($store, $doc, $body, $filename, self::actor($key));
        $out = ['stored' => $r];
        if ($r['status'] !== 'unchanged' && Http::query('compile') !== '0') $out['compiled'] = Compiler::run($store);
        Http::json($out, $r['status'] === 'created' ? 201 : 200);
    }

    private static function compile(array $auth): never
    {
        [$store, $key] = $auth;
        $r = Compiler::run($store);
        $store->log(self::actor($key), 'compile', "{$r['races']} races, {$r['ms']} ms");
        Http::json(['compiled' => $r]);
    }
}
