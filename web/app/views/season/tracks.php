<?php
use PitWall\F;
use PitWall\Http;
use PitWall\UI;

/** @var array $S @var array $tracks @var string $trackId */
$pages = $S['track_pages'];
$lastTrack = $S['rounds'][count($S['rounds']) - 1]['track_id'];
$tp = null;
foreach ($pages as $p) if ((string) $p['track_id'] === $trackId) $tp = $p;  // Site checked the id exists
foreach ($pages as $p) if (!$tp && $p['track_id'] === $lastTrack) $tp = $p;
$info = $tracks[(string) $tp['track_id']] ?? null;
$rounds = array_values(array_filter($S['rounds'], fn($r) => in_array($r['round'], $tp['rounds'], true)));
$otherSeasons = array_filter($info['seasons'] ?? [], fn($s) => $s['slug'] !== $S['slug']);

$payload = [
    'track' => $tp['track'],
    'map' => $info['map'] ?? null,
    'lengthM' => $tp['hotspots']['length_m'] ?? $info['length_m'] ?? null,
    'season' => $tp['hotspots'],
    'byRound' => (object) $tp['by_round'],
    'all' => $otherSeasons && !empty($info['hotspots_all']) ? $info['hotspots_all'] : null,
    'harvested' => $tp['harvested_rounds'],
    'pending' => $tp['pending'],
    'splits' => $tp['splits'],
    'slots' => (object) array_column($S['drivers'], 'color', 'name'),
];
\PitWall\View::push('head', '<script src="' . e(Http::asset('js/track.js')) . '" defer></script>');
$roundsText = implode(' · ', array_map(fn($r) => 'Round ' . $r['round'] . ' · ' . F::date($r['date'], false), $rounds));
?>
<div class="phead">
  <span class="kicker"><?= $roundsText ?></span>
  <h1><?= e($tp['track']) ?></h1>
  <p><mark><?= $tp['laps'] ?> team laps</mark><?= $info && $info['length_m'] ? ' · ' . e(F::km($info['length_m'])) . ' lap' : '' ?> · crash hotspots and sector splits come from the race replay</p>
</div>

<nav class="picker" aria-label="Tracks">
  <?php foreach ($pages as $p): ?>
    <a href="<?= e(Http::url($S['slug'] . '/tracks/' . $p['track_id'])) ?>"<?= $p['track_id'] === $tp['track_id'] ? ' aria-current="page"' : '' ?>>
      <?= UI::outline($tracks[(string) $p['track_id']]['map'] ?? null) ?>
      <span>R<?= implode(', R', $p['rounds']) ?> <?= e(F::trackTiny($p['track_short'])) ?></span>
    </a>
  <?php endforeach ?>
</nav>

<div class="g5 tiles">
  <?= UI::tile('Team incidents', '<span>' . $tp['team_inc'] . '</span>', 'here', F::fix($tp['team_inc_per_10'], 1) . ' per 10 laps · season ' . F::fix($tp['season_team_inc_per_10'], 1), 'hot') ?>
  <?= UI::tile('Cleanest here', $tp['cleanest'] ? UI::driver($tp['cleanest']['driver'], false, null, true) : F::DASH, '', $tp['cleanest'] ? F::fix($tp['cleanest']['inc_per_10'], 1) . ' per 10 laps' : '') ?>
  <?= UI::tile('Roughest here', $tp['roughest'] ? UI::driver($tp['roughest']['driver'], false, null, true) : F::DASH, '', $tp['roughest'] ? F::fix($tp['roughest']['inc_per_10'], 1) . ' per 10 laps' : '') ?>
  <?= UI::tile('Whole field', '<span>' . $tp['field_inc'] . '</span>', 'inc', $tp['ai_inc'] . ' of them by AI drivers') ?>
  <?= UI::tile('Difficulty', '<span>' . ($tp['difficulty_rank'] ? '#' . $tp['difficulty_rank'] : F::DASH) . '</span>', 'of ' . count($S['tracks']), 'incidents, consistency and places lost') ?>
</div>

<div id="track-app" class="track-app">
  <script type="application/json" id="track-data"><?= UI::json($payload) ?></script>
  <noscript>
    <div class="panel" style="margin-top:var(--gap)"><div class="panel-b">The track map, hotspots and sector splits need JavaScript.</div></div>
  </noscript>
</div>

<?= UI::panel('Drivers at ' . F::trackTiny($tp['track_short']), 'Incidents per 10 laps compared with each driver’s season rate') ?>
  <div class="scroll">
    <table class="tbl">
      <thead><tr><th>Driver</th><?php if (count($rounds) > 1): ?><th>Rnd</th><?php endif ?><th>Car</th><th class="num">Grid → finish</th><th class="num">Laps</th>
        <th class="num">Incidents</th><th class="num">Per 10 laps</th><th class="num">vs season</th><th class="num" title="Off-tracks and spins found in the replay">Off / spins</th></tr></thead>
      <tbody>
        <?php foreach ($tp['drivers'] as $d):
          $delta = $d['inc_per_10'] !== null && $d['season_inc_per_10'] !== null ? $d['inc_per_10'] - $d['season_inc_per_10'] : null; ?>
          <tr>
            <td class="name"><?= UI::driver($d['driver']) ?></td>
            <?php if (count($rounds) > 1): ?><td class="mono">R<?= $d['round'] ?></td><?php endif ?>
            <td class="dim"><?= e(F::carShort($d['car'])) ?></td>
            <td class="num"><?= F::ord($d['start']) ?> → <span class="pbadge<?= $d['finish'] === 1 ? ' p1' : '' ?>"><?= F::ord($d['finish']) ?></span></td>
            <td class="num"><?= $d['laps'] ?></td>
            <td class="num strong"><?= $d['inc'] ?></td>
            <td class="num"><?= F::fix($d['inc_per_10'], 1) ?></td>
            <td class="num <?= $delta !== null && $delta <= 0 ? 'good' : '' ?>"><?= $delta === null ? F::DASH : F::sgn($delta, 1) ?></td>
            <td class="num dim"><?= $d['markers'] ?? F::DASH ?></td>
          </tr>
        <?php endforeach ?>
      </tbody>
    </table>
  </div>
<?= UI::end() ?>

<div class="g2 g">
  <?php foreach ($rounds as $r): ?>
    <?= UI::panel('Round ' . $r['round'] . ' result', '') ?>
      <p class="note" style="margin:0"><?= F::date($r['date'], false) ?> · <?= $r['humans'] ?> drivers + <?= $r['ai'] ?> AI · <?= $r['laps'] ?> laps · SoF <?= $r['sof'] ?? F::DASH ?></p>
      <dl class="dl">
        <dt>Winner</dt><dd><?= UI::driver($r['winner'], $r['winner_ai']) ?></dd>
        <dt>Pole</dt><dd><?= UI::driver($r['pole'], $r['pole_ai']) ?></dd>
        <dt>Fastest lap</dt><dd><?= F::lap($r['fastest']['time'] ?? null) ?> <span class="dim"><?= e($r['fastest']['name'] ?? '') ?></span></dd>
        <dt>Team fastest</dt><dd><?= F::lap($r['team_fastest']['time'] ?? null) ?> <span class="dim"><?= e($r['team_fastest']['name'] ?? '') ?></span></dd>
        <dt>Cautions</dt><dd><?= $r['cautions'] ?></dd>
        <dt>Field incidents</dt><dd><?= $r['field_inc'] ?></dd>
      </dl>
    <?= UI::end() ?>
  <?php endforeach ?>
</div>
