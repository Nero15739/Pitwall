<?php
/* Parity check: the PHP compiler must publish the same numbers as the v2 TypeScript compiler.

     php tests/parity.php <reference dir>

   <reference dir> holds seasons.json, season-*.json and tracks.json from `npm run compile`.
   Inputs come from data/ and track maps from .cache/tracks (no network). */
declare(strict_types=1);

define('PITWALL_ROOT', dirname(__DIR__) . '/web');
require PITWALL_ROOT . '/app/bootstrap.php';

use PitWall\Compiler;
use PitWall\Stats\Race;

$repo = dirname(__DIR__);
$refDir = $argv[1] ?? "{$repo}/public/data";

// ---- inputs, laid out like v2: data/<season>/eventresult-*.json, data/incidents/incidents-*.json
$seasons = [];
foreach (glob("{$repo}/data/*", GLOB_ONLYDIR) as $dir) {
    $name = basename($dir);
    if ($name === 'incidents' || $name[0] === '.') continue;
    $files = glob("{$dir}/eventresult-*.json");
    sort($files);
    foreach ($files as $f) $seasons[$name][] = Race::process(json_decode(file_get_contents($f), true), basename($f));
}
$harvests = [];
foreach (glob("{$repo}/data/incidents/incidents-*.json") as $f) {
    $h = json_decode(file_get_contents($f), true);
    $harvests[$h['subsession']] = $h;
}
$maps = function (array $ids) use ($repo) {
    $out = [];
    foreach ($ids as $id) {
        $c = json_decode((string) @file_get_contents("{$repo}/.cache/tracks/{$id}.json"), true);
        $out[$id] = $c['map'] ?? null;
    }
    return $out;
};

$t0 = hrtime(true);
$ref = json_decode(file_get_contents("{$refDir}/seasons.json"), true);
$out = Compiler::build($seasons, $harvests, $maps, $ref['generated'], 'https://raw.githubusercontent.com/xikxp1/iRaceHUD/main/static/track_info_data/');
$ms = (hrtime(true) - $t0) / 1e6;

// ---- compare
$diffs = [];
$cmp = function ($a, $b, string $path) use (&$cmp, &$diffs) {
    if (count($diffs) > 40) return;
    if (is_array($a) && is_array($b)) {
        if (!$a && !$b) return; // [] vs {}
        $ka = array_map('strval', array_keys($a));
        $kb = array_map('strval', array_keys($b));
        sort($ka); sort($kb);
        if ($ka !== $kb) {
            $diffs[] = "{$path}: keys differ, only PHP: " . implode(',', array_diff($ka, $kb)) . ' / only TS: ' . implode(',', array_diff($kb, $ka));
            return;
        }
        if (array_is_list($a) !== array_is_list($b) && $a && $b) { /* JSON order only */ }
        foreach ($a as $k => $v) $cmp($v, $b[$k] ?? $b[(string) $k], "{$path}.{$k}");
        return;
    }
    if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
        if (abs($a - $b) > 1e-9 * max(1, abs($b))) $diffs[] = "{$path}: PHP " . var_export($a, true) . ' vs TS ' . var_export($b, true);
        return;
    }
    if ($a !== $b) $diffs[] = "{$path}: PHP " . json_encode($a, JSON_UNESCAPED_UNICODE) . ' vs TS ' . json_encode($b, JSON_UNESCAPED_UNICODE);
};

$cmp($out['index'], $ref, 'seasons.json');
foreach ($out['seasons'] as $slug => $season) {
    $cmp($season, json_decode(file_get_contents("{$refDir}/season-{$slug}.json"), true), "season-{$slug}");
}
$cmp($out['tracks'], json_decode(file_get_contents("{$refDir}/tracks.json"), true), 'tracks.json');

$n = array_sum(array_map('count', $seasons));
printf("built %d season(s), %d race(s), %d harvest(s) in %.1f ms\n", count($out['seasons']), $n, count($harvests), $ms);
if ($diffs) {
    echo count($diffs) . "+ difference(s):\n  " . implode("\n  ", $diffs) . "\n";
    exit(1);
}
echo "PARITY OK: every value matches the v2 compiler\n";
