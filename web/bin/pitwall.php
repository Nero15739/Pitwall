<?php
/* Command-line tools (for SSH on Hostinger, or local testing).

     php bin/pitwall.php import <data dir>    upload every eventresult-*.json (season = its folder name)
                                              and incidents-*.json under a v2-style data folder
     php bin/pitwall.php compile              rebuild the published data
     php bin/pitwall.php create-admin <user>  create the admin account (asks for the password)
     php bin/pitwall.php reset-password <user>
     php bin/pitwall.php create-key <name>    make an API key (printed once)
     php bin/pitwall.php seed-track-cache <dir>  import v2's .cache/tracks so maps work offline */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('PITWALL_ROOT', dirname(__DIR__));
require PITWALL_ROOT . '/app/bootstrap.php';

use PitWall\Compiler;
use PitWall\Ingest;
use PitWall\Store;

$cmd = $argv[1] ?? 'help';
$arg = $argv[2] ?? null;
$say = fn(string $s) => fwrite(STDOUT, $s . PHP_EOL);
$fail = function (string $s): never { fwrite(STDERR, $s . PHP_EOL); exit(1); };

function askPassword(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    if (DIRECTORY_SEPARATOR === '/') system('stty -echo');
    $p = trim((string) fgets(STDIN));
    if (DIRECTORY_SEPARATOR === '/') system('stty echo');
    fwrite(STDOUT, PHP_EOL);
    return $p;
}

try {
    $store = Store::open();
    switch ($cmd) {
        case 'import':
            if (!$arg || !is_dir($arg)) $fail('usage: import <data dir>');
            $n = 0;
            foreach (glob(rtrim($arg, '/\\') . '/*', GLOB_ONLYDIR) as $dir) {
                $season = basename($dir);
                if ($season === 'incidents' || $season[0] === '.') continue;
                foreach (glob("{$dir}/eventresult-*.json") as $f) {
                    $r = Ingest::any($store, file_get_contents($f), $season, basename($f), 'cli');
                    $say(sprintf('  %-9s %s → %s (%s)', $r['status'], basename($f), $r['season'], $r['track']));
                    $n++;
                }
            }
            foreach (glob(rtrim($arg, '/\\') . '/incidents/incidents-*.json') as $f) {
                $r = Ingest::any($store, file_get_contents($f), null, basename($f), 'cli');
                $say(sprintf('  %-9s %s (%d incidents%s)', $r['status'], basename($f), $r['incidents'], $r['matched'] ? '' : ', no matching race yet'));
                $n++;
            }
            $say("{$n} file(s) read");
            // fall through to compile
        case 'compile':
            $r = Compiler::run($store, $say);
            $say(sprintf('compiled %d season(s), %d race(s), %d harvest(s) in %d ms', $r['seasons'], $r['races'], $r['harvests'], $r['ms']));
            foreach ($r['errors'] as $e) $say("  ERROR {$e}");
            exit($r['errors'] ? 1 : 0);
        case 'seed-track-cache':
            if (!$arg || !is_dir($arg)) $fail('usage: seed-track-cache <.cache/tracks dir>');
            foreach (glob(rtrim($arg, '/\\') . '/*.json') as $f) {
                $c = json_decode(file_get_contents($f), true);
                $id = basename($f, '.json') === '_settings' ? -1 : (int) basename($f, '.json');
                if ($id === -1) $c = ['fetched' => $c['fetched'], 'map' => $c['data'], 'error' => null];
                $store->saveTrackMap($id, ['fetched' => $c['fetched'], 'map' => $c['map'], 'error' => $c['error'] ?? null]);
                $say("  track {$id}");
            }
            break;
        case 'create-admin':
        case 'reset-password':
            if (!$arg) $fail("usage: {$cmd} <username>");
            $pw = askPassword('Password (12+ characters): ');
            if (strlen($pw) < 12) $fail('Use at least 12 characters.');
            if ($cmd === 'create-admin') {
                if ($store->userCount() > 0) $fail('An admin account already exists. Use reset-password.');
                $store->createUser($arg, $pw);
            } else {
                $u = $store->user($arg) ?? $fail("No account called {$arg}.");
                $store->setPassword((int) $u['id'], $pw);
            }
            $store->log('cli', $cmd, $arg);
            $say('Done.');
            break;
        case 'create-key':
            if (!$arg) $fail('usage: create-key <name>');
            $key = $store->createApiKey($arg);
            $store->log('cli', 'key.create', $arg);
            $say($key);
            break;
        default:
            $say(trim(preg_replace('/^.*?\*\s|\s*\*\/.*$/s', '', file_get_contents(__FILE__, false, null, 0, 1200))));
    }
} catch (\PitWall\UserError $e) {
    $fail($e->getMessage());
}
