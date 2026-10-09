<?php
use PitWall\F;
use PitWall\Num;
use PitWall\UI;

/** @var array $S */
$R = $S['rounds'];
$names = array_column($S['standings'], 'driver');
$res = [];
foreach ($S['results'] as $x) $res[$x['driver']][$x['round']] = $x;
$last = (string) $R[count($R) - 1]['round'];
?>
<div class="phead">
  <span class="kicker">Garage</span>
  <h1>Cars</h1>
  <p>What everyone drove, and which car was fastest at each track. <mark>Car pace uses the whole field, AI included</mark>, so every car has a benchmark.</p>
</div>

<?= UI::panel('Fastest car at each track', 'Best lap by each car in the field. Bars take the colour of whoever set the car’s best lap; grey is AI.',
    UI::seg('cars-round', array_combine(array_map(fn($r) => (string) $r['round'], $R), array_map(fn($r) => 'R' . $r['round'] . ' ' . F::trackTiny($r['track_short']), $R)), $last, 'Round')) ?>
  <?php foreach ($R as $r): $cp = $r['car_pace']; ?>
    <div class="g2"<?= UI::pane('cars-round', $r['round'], $last) ?>>
      <div>
        <p class="note" style="margin:0 0 12px"><?= e($r['track']) ?> · <?= $r['starters'] ?> cars</p>
        <?= UI::bars(array_map(fn($c) => [
            'label' => F::carShort($c['car']), 'value' => $c['best'] - $cp[0]['best'],
            'text' => $c === $cp[0] ? F::lap($c['best']) : '+' . Num::fixed($c['best'] - $cp[0]['best'], 3) . 's',
            'ai' => $c['ai'], 'slot' => $c['ai'] ? null : UI::slot($c['by']),
            'tip' => ['title' => $c['car'], 'lines' => [['value' => F::lap($c['best']), 'label' => 'by ' . $c['by'] . ($c['ai'] ? ' (AI)' : '')],
                ['value' => (string) $c['entries'], 'label' => 'in field · avg best ' . F::lap($c['avg_best'])]]],
        ], $cp), 'Seconds off the fastest car') ?>
      </div>
      <div class="scroll">
        <table class="tbl compact">
          <thead><tr><th>#</th><th>Car</th><th class="num">Best lap</th><th>Set by</th><th class="num">In field</th></tr></thead>
          <tbody>
            <?php foreach ($cp as $i => $c): ?>
              <tr class="<?= $i === 0 ? 'p1' : '' ?>"><td class="pos"><?= $i + 1 ?></td><td><?= e(F::carShort($c['car'])) ?></td>
                <td class="num <?= $i === 0 ? 'strong' : '' ?>"><?= F::lap($c['best']) ?></td><td class="name"><?= UI::driver($c['by'], $c['ai']) ?></td><td class="num"><?= $c['entries'] ?></td></tr>
            <?php endforeach ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endforeach ?>
<?= UI::end() ?>

<?= UI::panel('Season summary by track', 'The quickest car at each round and who drove it') ?>
  <div class="scroll">
    <table class="tbl">
      <thead><tr><th>Rnd</th><th>Track</th><th>Fastest car</th><th>Set by</th><th class="num">Lap</th><th>Next car</th><th class="num">Gap</th><th>Team winner’s car</th></tr></thead>
      <tbody>
        <?php foreach ($R as $r):
          $a = $r['car_pace'][0] ?? null; $b = $r['car_pace'][1] ?? null;
          $tw = array_values(array_filter($S['results'], fn($x) => $x['round'] === $r['round'] && $x['team_finish'] === 1))[0] ?? null; ?>
          <tr>
            <td class="pos">R<?= $r['round'] ?></td><td><?= e($r['track_short']) ?></td><td class="strong"><?= e(F::carShort($a['car'] ?? null)) ?></td>
            <td class="name"><?= $a ? UI::driver($a['by'], $a['ai']) : F::DASH ?></td><td class="num"><?= F::lap($a['best'] ?? null) ?></td>
            <td class="dim"><?= e(F::carShort($b['car'] ?? null)) ?></td><td class="num"><?= $a && $b ? '+' . Num::fixed($b['best'] - $a['best'], 3) . 's' : F::DASH ?></td>
            <td><?= $tw ? e(F::carShort($tw['car'])) : F::DASH ?></td>
          </tr>
        <?php endforeach ?>
      </tbody>
    </table>
  </div>
<?= UI::end() ?>

<?= UI::panel('Who drove what', 'Each driver’s car at every round, with their overall finish') ?>
  <div class="scroll">
    <table class="tbl">
      <thead><tr><th>Driver</th><?php foreach ($R as $r): ?><th>R<?= $r['round'] ?> <?= e(F::trackTiny($r['track_short'])) ?></th><?php endforeach ?></tr></thead>
      <tbody>
        <?php foreach ($names as $n): ?>
          <tr>
            <td class="name"><?= UI::driver($n) ?></td>
            <?php foreach ($R as $r): $x = $res[$n][$r['round']] ?? null; ?>
              <td><?php if ($x): ?><span class="dim"><?= e(F::carShort($x['car'])) ?></span> <span class="pbadge<?= $x['finish'] === 1 ? ' p1' : '' ?>"><?= F::ord($x['finish']) ?></span><?php else: ?><span class="dim"><?= F::DASH ?></span><?php endif ?></td>
            <?php endforeach ?>
          </tr>
        <?php endforeach ?>
      </tbody>
    </table>
  </div>
<?= UI::end() ?>

<?= UI::panel('Car performance for the team', 'How each car did in the team’s hands across the season') ?>
  <div class="scroll">
    <table class="tbl">
      <thead><tr><th>Car</th><th class="num">Starts</th><th>Drivers</th><th class="num">Wins</th><th class="num">Avg finish</th><th class="num">Gap to team best</th><th class="num">Inc / 10 laps</th><th class="num">Fastest car at</th></tr></thead>
      <tbody>
        <?php foreach ($S['cars'] as $c): ?>
          <tr>
            <td class="strong"><?= e($c['car']) ?></td><td class="num"><?= $c['starts'] ?></td>
            <td><span class="chips"><?php foreach ($c['drivers'] as $d): ?><?= UI::driver($d, false, null, true) ?><?php endforeach ?></span></td>
            <td class="num"><?= $c['wins'] ?></td><td class="num"><?= F::fix($c['avg_finish'], 1) ?></td><td class="num"><?= F::pct($c['avg_gap_team_pct']) ?></td>
            <td class="num"><?= F::per10($c['avg_inc_per_lap']) ?></td><td class="num"><?= $c['fastest_at'] ? implode(', ', array_map(fn($r) => "R{$r}", $c['fastest_at'])) : F::DASH ?></td>
          </tr>
        <?php endforeach ?>
      </tbody>
    </table>
  </div>
  <p class="note">With only a handful of starts per car these are indications, not verdicts. A car’s numbers say as much about who drove it as about the car.</p>
<?= UI::end() ?>
