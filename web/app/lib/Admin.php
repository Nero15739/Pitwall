<?php
/* The admin panel at /admin: sign in, upload race results and harvested replays, manage seasons
   and races, issue API keys, change the password. Every change is a CSRF-checked POST that
   redirects back (post/redirect/get), and every change lands in the activity log. */
declare(strict_types=1);

namespace PitWall;

final class Admin
{
    private static Store $store;
    private static ?array $user = null;

    public static function handle(string $path, string $method): void
    {
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex, nofollow');
        Auth::start();
        self::$store = Store::open();
        $route = rtrim($path, '/') ?: '/';

        // first run: no account yet
        if (self::$store->userCount() === 0) {
            $method === 'POST' && $route === '/setup' ? self::setup() : self::page('admin/setup', ['title' => 'Set up', 'error' => null]);
        }
        if ($route === '/setup') Http::redirect('admin');
        if ($route === '/login') {
            $method === 'POST' ? self::login() : self::page('admin/login', ['title' => 'Sign in', 'error' => null, 'username' => '']);
        }

        self::$user = Auth::user(self::$store);
        if (!self::$user) Http::redirect('admin/login');

        if ($method === 'POST') {
            Auth::checkCsrf();
            try {
                match ($route) {
                    '/logout' => self::logout(),
                    '/upload' => self::upload(),
                    '/compile' => self::compile(),
                    '/races/move' => self::moveRace(),
                    '/races/delete' => self::deleteRace(),
                    '/harvests/delete' => self::deleteHarvest(),
                    '/incidents/cut' => self::cutIncidents(),
                    '/schedule/delete' => self::deleteSchedule(),
                    '/seasons/create' => self::createSeason(),
                    '/seasons/rename' => self::renameSeason(),
                    '/seasons/delete' => self::deleteSeason(),
                    '/keys/create' => self::createKey(),
                    '/keys/revoke' => self::revokeKey(),
                    '/keys/delete' => self::deleteKey(),
                    '/settings/account' => self::account(),
                    '/settings/overrides' => self::overrides(),
                    '/settings/maps' => self::refreshMaps(),
                    default => App::error(404, 'Not found', 'There’s nothing to post to here.'),
                };
            } catch (UserError $e) {
                Auth::flash('error', $e->getMessage());
                Http::redirect(self::back());
            }
        }

        match ($route) {
            '/' => self::dashboard(),
            '/races' => self::page('admin/races', [
                'title' => 'Races', 'seasons' => self::$store->seasons(), 'races' => self::$store->races(), 'harvests' => self::$store->harvests(),
                'schedules' => array_column(self::$store->schedules(), null, 'season_id'),
            ]),
            '/incidents' => self::incidentsPage(),
            '/schedule/download' => self::downloadSchedule(),
            '/keys' => self::page('admin/keys', ['title' => 'API keys', 'keys' => self::$store->apiKeys(), 'newKey' => self::takeNewKey()]),
            '/settings' => self::page('admin/settings', [
                'title' => 'Settings', 'overrides' => self::$store->setting('track_overrides', ''), 'activity' => self::$store->activity(100),
            ]),
            default => App::error(404, 'Page not found', 'There’s no admin page at this address.'),
        };
    }

    private static function page(string $view, array $vars): never
    {
        $vars += ['user' => self::$user, 'flash' => Auth::takeFlash(), 'pageTitle' => ($vars['title'] ?? 'Admin') . ' · Admin', 'route' => $view];
        View::send(View::render($view, $vars, 'admin/layout'));
    }

    private static function actor(): string
    {
        return 'admin:' . (self::$user['username'] ?? '?');
    }

    private static function back(): string
    {
        $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $path = parse_url($ref, PHP_URL_PATH) ?: '';
        $base = Http::base();
        if ($base !== '' && str_starts_with($path, $base)) $path = substr($path, strlen($base));
        return str_starts_with($path, '/admin') && !str_starts_with($path, '/admin/login') ? ltrim($path, '/') : 'admin';
    }

    // ---------------------------------------------------------------- sign in / first run
    private static function setup(): never
    {
        Auth::checkCsrf();
        $token = Auth::installToken();
        $u = trim(Http::post('username'));
        $pw = Http::post('password');
        $err = null;
        if (self::$store->throttled('setup:' . Http::ip(), 10)) $err = 'Too many attempts. Wait 15 minutes.';
        elseif (!hash_equals($token, trim(Http::post('token')))) {
            self::$store->throttle('setup:' . Http::ip(), 10, 900);
            $err = 'That setup token doesn’t match the one in storage/INSTALL-TOKEN.txt.';
        }
        $err ??= Auth::checkUsername($u) ?? Auth::checkPassword($pw, Http::post('confirm'));
        if ($err) self::page('admin/setup', ['title' => 'Set up', 'error' => $err]);
        self::$store->createUser($u, $pw);
        Auth::clearInstallToken();
        self::$store->log("admin:{$u}", 'setup', 'admin account created', Http::ip());
        Auth::login(self::$store, $u, $pw);
        Auth::flash('ok', 'Your admin account is ready. Upload some results, or create an API key to push them from your PC.');
        Http::redirect('admin');
    }

    private static function login(): never
    {
        Auth::checkCsrf();
        $u = trim(Http::post('username'));
        $err = Auth::login(self::$store, $u, Http::post('password'));
        if ($err) self::page('admin/login', ['title' => 'Sign in', 'error' => $err, 'username' => $u]);
        Http::redirect('admin');
    }

    private static function logout(): never
    {
        self::$store->log(self::actor(), 'logout');
        Auth::logout();
        Http::redirect('admin/login');
    }

    // ---------------------------------------------------------------- dashboard + uploads
    private static function dashboard(): never
    {
        $races = self::$store->races();
        $seasons = self::$store->seasons();
        $harvests = self::$store->harvests();
        $index = Data::index();
        self::page('admin/dashboard', [
            'title' => 'Dashboard', 'seasons' => $seasons, 'races' => $races, 'harvests' => $harvests,
            'keys' => count(array_filter(self::$store->apiKeys(), fn($k) => !$k['revoked_at'])),
            'index' => $index, 'activity' => self::$store->activity(12),
        ]);
    }

    private static function upload(): never
    {
        $files = $_FILES['files'] ?? null;
        if (!$files || !is_array($files['name'])) throw new UserError('Choose one or more JSON files to upload.');
        $season = Http::post('season') === '__new' ? trim(Http::post('new_season')) : trim(Http::post('season'));
        if (Http::post('season') === '__new' && $season === '') throw new UserError('Type a name for the new season.');
        $lines = [];
        $changed = 0;
        $failed = 0;
        foreach ($files['name'] as $i => $name) {
            $name = basename((string) $name);
            $err = $files['error'][$i];
            if ($err === UPLOAD_ERR_NO_FILE) continue;
            if ($err !== UPLOAD_ERR_OK) {
                $failed++;
                $lines[] = "✗ {$name}: " . ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE ? 'too large for the server’s upload limit' : "upload failed (code {$err})");
                continue;
            }
            try {
                $r = Ingest::any(self::$store, (string) file_get_contents($files['tmp_name'][$i]), $season !== '' ? $season : null, $name, self::actor());
                if ($r['status'] !== 'unchanged') $changed++;
                $lines[] = match ($r['type']) {
                    'result' => "✓ {$name}: {$r['status']} · {$r['track']} → {$r['season']}",
                    'schedule' => "✓ {$name}: {$r['status']} · schedule of {$r['rounds']} round(s) → {$r['season']}",
                    default => "✓ {$name}: {$r['status']} · {$r['incidents']} incidents" . ($r['matched'] ? " · {$r['season']}" : ' · no matching race yet'),
                };
            } catch (UserError $e) {
                $failed++;
                $lines[] = '✗ ' . $e->getMessage();
            }
        }
        if (!$lines) throw new UserError('Choose one or more JSON files to upload.');
        if ($changed) {
            $c = Compiler::run(self::$store);
            $lines[] = "Published in {$c['ms']} ms: {$c['seasons']} season(s), {$c['races']} race(s)" . ($c['errors'] ? ' · ' . count($c['errors']) . ' error(s)' : '');
            foreach ($c['errors'] as $e) $lines[] = "✗ {$e}";
        }
        Auth::flash($failed ? ($changed ? 'warn' : 'error') : 'ok',
            $changed ? "Uploaded {$changed} file(s)" . ($failed ? ", {$failed} failed" : '') : ($failed ? 'Nothing was uploaded' : 'Nothing new: those files are already here'), $lines);
        Http::redirect('admin');
    }

    private static function compile(): never
    {
        $c = Compiler::run(self::$store);
        self::$store->log(self::actor(), 'compile', "{$c['races']} races, {$c['ms']} ms");
        Auth::flash($c['errors'] ? 'warn' : 'ok', "Rebuilt {$c['seasons']} season(s) from {$c['races']} race(s) in {$c['ms']} ms", $c['errors']);
        Http::redirect(self::back());
    }

    // ---------------------------------------------------------------- races, harvests, seasons
    private static function sub(): int
    {
        $s = (int) Http::post('subsession');
        if ($s <= 0) throw new UserError('Missing subsession.');
        return $s;
    }

    private static function moveRace(): never
    {
        $sub = self::sub();
        $target = Http::post('season');
        $season = $target === '__new' ? self::$store->ensureSeason(Http::post('new_season')) : self::$store->season((int) $target);
        if (!$season) throw new UserError('Pick a season.');
        self::$store->moveRace($sub, (int) $season['id']);
        self::$store->log(self::actor(), 'race.move', "{$sub} → {$season['name']}");
        self::recompile("Moved race {$sub} to {$season['name']}.");
    }

    private static function deleteRace(): never
    {
        $sub = self::sub();
        $race = self::$store->race($sub) ?? throw new UserError('That race is already gone.');
        self::$store->deleteRace($sub);
        self::$store->log(self::actor(), 'race.delete', "{$sub} {$race['track']} ({$race['season']})");
        self::recompile("Deleted {$race['track']} ({$sub}). Its harvested replay, if any, is kept in case you re-upload the race.");
    }

    private static function deleteHarvest(): never
    {
        $sub = self::sub();
        self::$store->deleteHarvest($sub);
        self::$store->log(self::actor(), 'harvest.delete', (string) $sub);
        self::recompile("Deleted the harvested replay for {$sub}.");
    }

    // ---------------------------------------------------------------- incident cut-offs
    /** Each race's end: when the leader and the last car finished, the incidents around it, and where they stop counting. */
    private static function incidentsPage(): never
    {
        $cuts = Cutoffs::all(self::$store);
        $races = self::$store->races();
        // round numbers within each season, oldest first
        $round = [];
        $bySeason = [];
        foreach (array_reverse($races) as $r) $round[(int) $r['subsession']] = $bySeason[$r['season_id']] = ($bySeason[$r['season_id']] ?? 0) + 1;
        $items = [];
        foreach ($races as $r) {
            $sub = (int) $r['subsession'];
            $harvest = null;
            if ((int) $r['harvested']) {
                try { $harvest = Store::decodeRaw((string) self::$store->harvestRaw($sub)); } catch (\Throwable $e) { App::log($e); }
            }
            $record = json_decode((string) (self::$store->race($sub)['processed'] ?? ''), true) ?: null;
            $items[] = Cutoffs::review($r + ['round' => $round[$sub]], $record, $harvest, Live::raceLog($sub), $cuts[$sub] ?? null);
        }
        self::page('admin/incidents', ['title' => 'Incidents', 'items' => $items]);
    }

    private static function cutIncidents(): never
    {
        $sub = self::sub();
        $race = self::$store->race($sub) ?? throw new UserError('That race is no longer here.');
        $mode = Http::post('preset') !== '' ? 'time' : Http::post('mode');
        $official = Http::post('official') === '1';
        $also = $official ? ', and in the official incident points' : '';
        if ($mode === 'all') {
            Cutoffs::set(self::$store, $sub, null, null, false, self::actor());
            self::$store->log(self::actor(), 'incidents.cut', "{$sub} {$race['track']}: every incident counts");
            self::recompile("{$race['track']}: every incident counts again.");
        }
        if ($mode === 'finish') {
            Cutoffs::set(self::$store, $sub, 'finish', null, $official, self::actor());
            self::$store->log(self::actor(), 'incidents.cut', "{$sub} {$race['track']}: each driver's finish" . ($official ? ', official points too' : ''));
            self::recompile("{$race['track']}: each driver’s incidents now stop when they take the chequered flag{$also}.");
        }
        if ($mode !== 'time') throw new UserError('Choose where this race’s incidents stop counting.');
        $until = Http::post('preset') !== '' ? (float) Http::post('preset') : Cutoffs::parse(Http::post('until'));
        if ($until <= 0) throw new UserError('The cut-off has to be after the start of the race.');
        Cutoffs::set(self::$store, $sub, 'time', $until, $official, self::actor());
        $at = Cutoffs::clock($until);
        self::$store->log(self::actor(), 'incidents.cut', "{$sub} {$race['track']}: up to {$at}" . ($official ? ', official points too' : ''));
        self::recompile("{$race['track']}: incidents now count up to {$at}{$also}.");
    }

    private static function deleteSchedule(): never
    {
        $id = (int) Http::post('id');
        $s = self::$store->season($id) ?? throw new UserError('That season no longer exists.');
        self::$store->deleteSchedule($id);
        self::$store->log(self::actor(), 'schedule.delete', $s['name']);
        self::recompile("Removed the schedule for {$s['name']}. Its upcoming rounds are gone from the site.");
    }

    /** The season's schedule as a file to edit and upload again; a starter file when it has none. */
    private static function downloadSchedule(): never
    {
        $s = self::$store->season((int) Http::query('season')) ?? App::error(404, 'Season not found', 'That season no longer exists.');
        $stored = self::$store->schedule((int) $s['id']);
        if ($stored) {
            $rounds = json_decode($stored['rounds'], true) ?: [];
        } else {
            // start from the rounds already raced, plus one example to overwrite
            $rounds = [];
            foreach (Data::season($s['slug'])['rounds'] ?? [] as $r) {
                $config = str_starts_with($r['track'], $r['track_short'] . ' – ') ? substr($r['track'], strlen($r['track_short'] . ' – ')) : null;
                $rounds[] = ['date' => $r['date'], 'track' => $r['track_short'], 'config' => $config, 'track_id' => $r['track_id']];
            }
            $rounds[] = [
                'date' => gmdate('Y-m-d', time() + 14 * 86400), 'track' => 'EXAMPLE: replace with the track name', 'config' => null,
                'track_id' => null, 'laps' => null, 'minutes' => null, 'cars' => null, 'note' => 'Copy this block for each round still to come',
            ];
        }
        $doc = ['type' => 'schedule', 'version' => 1, 'season' => $s['name'], 'rounds' => $rounds];
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="schedule-' . $s['slug'] . '.json"');
        echo json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private static function createSeason(): never
    {
        $s = self::$store->ensureSeason(Http::post('name'));
        self::$store->log(self::actor(), 'season.create', $s['name']);
        Auth::flash('ok', "Season {$s['name']} is ready. Move races into it, or pick it when uploading.");
        Http::redirect('admin/races');
    }

    private static function renameSeason(): never
    {
        $id = (int) Http::post('id');
        $old = self::$store->season($id) ?? throw new UserError('That season no longer exists.');
        self::$store->renameSeason($id, Http::post('name'));
        $new = self::$store->season($id);
        self::$store->log(self::actor(), 'season.rename', "{$old['name']} → {$new['name']}");
        self::recompile("Renamed {$old['name']} to {$new['name']}. Its address is now /{$new['slug']}.");
    }

    private static function deleteSeason(): never
    {
        $id = (int) Http::post('id');
        $s = self::$store->season($id) ?? throw new UserError('That season no longer exists.');
        self::$store->deleteSeason($id);
        self::$store->log(self::actor(), 'season.delete', $s['name']);
        self::recompile("Deleted the empty season {$s['name']}.");
    }

    private static function recompile(string $msg): never
    {
        $c = Compiler::run(self::$store);
        Auth::flash($c['errors'] ? 'warn' : 'ok', $msg, $c['errors']);
        Http::redirect(self::back());
    }

    // ---------------------------------------------------------------- API keys
    private static function createKey(): never
    {
        $name = Http::post('name');
        $key = self::$store->createApiKey($name);
        self::$store->log(self::actor(), 'key.create', trim($name));
        $_SESSION['new_key'] = ['name' => trim($name), 'key' => $key];
        Http::redirect('admin/keys');
    }

    private static function takeNewKey(): ?array
    {
        $k = $_SESSION['new_key'] ?? null;
        unset($_SESSION['new_key']);
        return $k;
    }

    private static function revokeKey(): never
    {
        $id = (int) Http::post('id');
        self::$store->revokeApiKey($id);
        self::$store->log(self::actor(), 'key.revoke', (string) $id);
        Auth::flash('ok', 'Key revoked. Anything still using it now gets “401 unauthorized”.');
        Http::redirect('admin/keys');
    }

    private static function deleteKey(): never
    {
        self::$store->deleteApiKey((int) Http::post('id'));
        Http::redirect('admin/keys');
    }

    // ---------------------------------------------------------------- settings
    private static function account(): never
    {
        $u = self::$user;
        if (!password_verify(Http::post('current'), $u['password_hash'])) throw new UserError('Your current password is wrong.');
        $name = trim((Http::post('username') ?: $u['username']));
        if ($name !== $u['username']) {
            if ($err = Auth::checkUsername($name)) throw new UserError($err);
            self::$store->setUsername((int) $u['id'], $name);
        }
        $pw = Http::post('password');
        if ($pw !== '') {
            if ($err = Auth::checkPassword($pw, Http::post('confirm'))) throw new UserError($err);
            self::$store->setPassword((int) $u['id'], $pw);
            session_regenerate_id(true);
        }
        self::$store->log("admin:{$name}", 'account.update', $pw !== '' ? 'password changed' : 'username changed');
        Auth::flash('ok', $pw !== '' ? 'Account updated, including your password.' : 'Account updated.');
        Http::redirect('admin/settings');
    }

    private static function overrides(): never
    {
        $raw = trim(Http::post('overrides'));
        if ($raw !== '') {
            $v = json_decode($raw, true);
            if (!is_array($v)) throw new UserError('Track overrides must be a JSON object, like {"250": {"offset": 0.516, "direction": 1}}.');
            foreach ($v as $id => $o) {
                if (!ctype_digit((string) $id) || !is_array($o)) throw new UserError('Each key is a track ID and each value an object.');
                foreach ($o as $k => $x) {
                    if (!in_array($k, ['offset', 'direction'], true) || !is_numeric($x)) throw new UserError("Only numeric “offset” and “direction” can be overridden (track {$id}).");
                }
            }
            $raw = json_encode($v, JSON_PRETTY_PRINT);
        }
        self::$store->setSetting('track_overrides', $raw);
        self::$store->log(self::actor(), 'settings.overrides');
        self::recompile('Track map calibration saved.');
    }

    private static function refreshMaps(): never
    {
        self::$store->forgetTrackMaps();
        self::$store->log(self::actor(), 'settings.maps');
        self::recompile('Track maps fetched again from iRaceHUD.');
    }
}
