<?php
use PitWall\F;
use PitWall\Http;
use PitWall\Num;
use PitWall\UI;

/** @var array $S */
$R = $S['rounds'];
$names = array_column($S['standings'], 'driver');
$res = [];
foreach ($S['results'] as $x) $res[$x['driver']][$x['round']] = $x;
$trackOf = array_column($R, null, 'round');
$byRate = array_values(array_filter($S['standings'], fn($s) => $s['inc_per_lap'] !== null));
usort($byRate, fn($a, $b) => $a['inc_per_lap'] <=> $b['inc_per_lap']);
$tracks = $S['tracks'];
usort($tracks, fn($a, $b) => $a['difficulty_rank'] <=> $b['difficulty_rank']);
$teamInc = array_sum(array_column($R, 'team_inc'));
$ipl = array_values(array_filter(array_column($S['results'], 'inc_per_lap'), fn($v) => $v !== null));
$u = fn(string $p) => e(Http::url($S['slug'] . $p));

$series = function (string $mode) use ($names, $R, $res) {
    return array_map(function ($n) use ($mode, $R, $res) {
        $tot = 0;
        return ['name' => $n, 'slot' => UI::slot($n), 'values' => array_map(function ($r) use ($n, $mode, $res, &$tot) {
            $x = $res[$n][$r['round']] ?? null;
            if (!$x) return $mode === 'cum' ? $tot : null;
            if ($mode === 'cum') return $tot += $x['inc'];
            if ($mode === 'rate') return $x['inc_per_lap'] !== null ? (float) Num::fixed($x['inc_per_lap'] * 10, 2) : null;
            return $x['inc'];
        }, $R)];
    }, $names);
};
$labels = array_map(fn($r) => 'R' . $r['round'] . ' ' . F::trackTiny($r['track_short']), $R);
$titles = array_map(fn($r) => $r['track'], $R);
$modes = ['race' => ['Per race', 'Incident points in each round', 'Incidents', 'int'],
          'cum' => ['Cumulative', 'Running total of incident points across the season', 'Incidents', 'int'],
          'rate' => ['Per 10 laps', 'Incidents per 10 laps completed in each round', 'Per 10 laps', 'd1']];
$clean = $byRate[0];
$rough = $byRate[count($byRate) - 1];
?>
<div class="phead">
  <span class="kicker">Race control</span>
  <h1>Incidents</h1>
  <p>Incident points per driver, which tracks bit hardest, and for whom. <mark>Rates are per 10 laps</mark> so short and long races compare fairly.</p>
</div>

<div class="g4 tiles">
  <?= UI::tile('Team incidents', '<span>' . $teamInc . '</span>', 'total', F::fix($teamInc / count($R), 1) . ' per round across the team', 'hot') ?>
  <?= UI::tile('Cleanest driver', UI::driver($clean['driver'], false, null, true), '', F::per10($clean['inc_per_lap']) . ' incidents per 10 laps') ?>
  <?= UI::tile('Most incident-prone', UI::driver($rough['driver'], false, null, true), '', F::per10($rough['inc_per_lap']) . ' incidents per 10 laps') ?>
  <?= UI::tile('Hardest track', '<a href="' . $u('/tracks/' . $trackOf[$tracks[0]['round']]['track_id']) . '">' . e(F::trackTiny($tracks[0]['track_short'])) . '</a>', '',
      F::per10($tracks[0]['inc_per_lap']) . ' per 10 laps · ' . $tracks[0]['inc'] . ' team incidents') ?>
</div>

<?= UI::panel('Incident points by driver', '', UI::seg('inc-mode', array_map(fn($m) => $m[0], $modes), 'race', 'Measure')) ?>
  <?php foreach ($modes as $key => [$label, $sub, $y, $fmt]): ?>
    <div<?= UI::pane('inc-mode', $key, 'race') ?>>
      <p class="note" style="margin:0 0 12px"><?= e($sub) ?></p>
      <?= UI::chart(['labels' => $labels, 'titles' => $titles, 'series' => $series($key), 'yTitle' => $y, 'fmt' => $fmt, 'height' => 300,
          'aria' => 'Incident points by driver and round: ' . strtolower($label)]) ?>
    </div>
  <?php endforeach ?>
<?= UI::end() ?>

<div class="g-2-1 g">
  <?= UI::panel('Difficulty ranking', 'Blends incident rate (50%), lap consistency (30%) and places lost from the grid (20%)') ?>
    <div class="scroll">
      <table class="tbl">
        <thead><tr><th>#</th><th>Track</th><th class="num">Inc / 10 laps</th><th class="num">Consistency</th><th class="num">Avg places</th><th class="num">Score</th></tr></thead>
        <tbody>
          <?php foreach ($tracks as $t): ?>
            <tr class="<?= $t['difficulty_rank'] === 1 ? 'p1' : '' ?>">
              <td class="pos"><?= str_pad((string) $t['difficulty_rank'], 2, '0', STR_PAD_LEFT) ?></td>
              <td><a href="<?= $u('/tracks/' . $trackOf[$t['round']]['track_id']) ?>"><?= e($t['track_short']) ?></a></td>
              <td class="num"><?= F::per10($t['inc_per_lap']) ?></td>
              <td class="num"><?= F::pct($t['avg_consistency_pct']) ?></td>
              <td class="num"><?= F::sgn($t['avg_gained'], 1) ?></td>
              <td class="num strong"><?= F::sgn($t['difficulty'], 2) ?></td>
            </tr>
          <?php endforeach ?>
        </tbody>
      </table>
    </div>
  <?= UI::end() ?>
  <?= UI::panel('Track difficulty', 'Team incidents per 10 laps at each track') ?>
    <?php $bt = $S['tracks']; usort($bt, fn($a, $b) => ($b['inc_per_lap'] ?? 0) <=> ($a['inc_per_lap'] ?? 0)); ?>
    <?= UI::bars(array_map(fn($t) => [
        'label' => F::trackTiny($t['track_short']), 'value' => ($t['inc_per_lap'] ?? 0) * 10, 'text' => F::per10($t['inc_per_lap']),
        'tip' => ['title' => $t['track'], 'lines' => [['value' => (string) $t['inc'], 'label' => 'team incidents'], ['value' => F::fix($t['avg_inc'], 1), 'label' => 'per driver']]],
    ], $bt), 'Incidents per 10 laps by track', null, 'var(--hot)') ?>
  <?= UI::end() ?>
</div>

<?= UI::panel('Incidents by driver and track', 'Incident count, shaded by rate per lap. The hotter the cell, the rougher the night.') ?>
  <div class="scroll">
    <table class="tbl compact">
      <thead><tr><th>Driver</th><?php foreach ($R as $r): ?><th class="c">R<?= $r['round'] ?> <?= e(F::trackTiny($r['track_short'])) ?></th><?php endforeach ?><th class="num">Total</th><th class="num">Per race</th></tr></thead>
      <tbody>
        <?php foreach ($S['standings'] as $s): ?>
          <tr>
            <td class="name"><?= UI::driver($s['driver']) ?></td>
            <?php foreach ($R as $r): $x = $res[$s['driver']][$r['round']] ?? null; ?>
              <?= UI::heat($x['inc_per_lap'] ?? null, $ipl ? min($ipl) : 0, $ipl ? max($ipl) : 1, $x ? (string) $x['inc'] : F::DASH, $x ? ['title' => $s['driver'] . ' · ' . $r['track_short'], 'lines' => [
                  ['value' => (string) $x['inc'], 'label' => "incidents in {$x['laps']} laps"], ['value' => F::per10($x['inc_per_lap']), 'label' => 'per 10 laps'], ['value' => F::ord($x['finish']), 'label' => 'finish']]] : null) ?>
            <?php endforeach ?>
            <td class="num strong"><?= $s['inc'] ?></td><td class="num"><?= F::fix($s['inc_per_race'], 1) ?></td>
          </tr>
        <?php endforeach ?>
      </tbody>
    </table>
  </div>
<?= UI::end() ?>

<h2 class="h2">Each driver’s toughest round</h2>
<div class="g3">
  <?php foreach ($names as $n): $h = $S['hardest_by_driver'][$n] ?? null; if (!$h) continue; ?>
    <div class="card dcard" style="--c:<?= UI::color(UI::slot($n)) ?>">
      <span class="dcard-band"></span>
      <div class="dcard-h"><?= UI::driver($n) ?></div>
      <div class="dcard-b">
        <dl class="dl" style="margin:0">
          <dt>Worst rate</dt><dd><strong><?= e($trackOf[$h['most_incidents']['round']]['track_short']) ?></strong> · <?= $h['most_incidents']['inc'] ?>x (<?= F::per10($h['most_incidents']['inc_per_lap']) ?>/10)</dd>
          <dt>Off the pace</dt><dd><strong><?= e($trackOf[$h['slowest_vs_team']['round']]['track_short']) ?></strong> · +<?= F::fix($h['slowest_vs_team']['gap_pct'], 2) ?>%</dd>
          <dt>Best result</dt><dd><strong><?= e($trackOf[$h['best_track']['round']]['track_short']) ?></strong> · <?= F::ord($h['best_track']['finish']) ?></dd>
        </dl>
      </div>
    </div>
  <?php endforeach ?>
</div>
