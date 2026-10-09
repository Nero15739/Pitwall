<?php
use PitWall\F;
use PitWall\Http;
use PitWall\Num;
use PitWall\UI;

/** @var array $S */
$R = $S['rounds'];
$trackOf = array_column($R, null, 'round');
$names = array_column($S['standings'], 'driver');
$st = array_column($S['standings'], null, 'driver');
$res = [];
foreach ($S['results'] as $x) $res[$x['driver']][$x['round']] = $x;

// the duel: two drivers picked with ?a=&b= (the selects submit themselves)
$qa = Http::query('a');
$qb = Http::query('b');
$A = in_array($qa, $names, true) ? $qa : $names[0];
$B = in_array($qb, $names, true) && $qb !== $A ? $qb : (array_values(array_diff($names, [$A]))[0] ?? $A);
$rows = [];
foreach ($R as $r) {
    $x = $res[$A][$r['round']] ?? null; $y = $res[$B][$r['round']] ?? null;
    if ($x && $y) $rows[] = [$r, $x, $y];
}
$tally = function (callable $f) use ($rows) {
    $t = [0, 0];
    foreach ($rows as [, $x, $y]) { $d = $f($x, $y); $t[0] += (int) ($d < 0); $t[1] += (int) ($d > 0); }
    return $t;
};
$battles = [
    'Finished ahead' => $tally(fn($x, $y) => $x['finish'] <=> $y['finish']),
    'Out-qualified' => $tally(fn($x, $y) => ($x['qual'] ?? 1e9) <=> ($y['qual'] ?? 1e9)),
    'Faster race lap' => $tally(fn($x, $y) => ($x['best'] ?? 1e9) <=> ($y['best'] ?? 1e9)),
    'Fewer incidents' => $tally(fn($x, $y) => $x['inc'] <=> $y['inc']),
];
$sA = $st[$A]; $sB = $st[$B];
$opts = fn(string $sel, ?string $skip = null) => implode('', array_map(fn($n) => $n === $skip ? '' : '<option value="' . e($n) . '"' . ($n === $sel ? ' selected' : '') . '>' . e($n) . '</option>', $names));
?>
<div class="phead">
  <span class="kicker">Inter-team rivalry</span>
  <h1>Head to head</h1>
  <p>How everyone stacks up against each other, race by race. <mark>Read across</mark>: the row driver against the column driver.</p>
</div>

<?= UI::panel('Head-to-head record', 'How many times the row driver beat the column driver', UI::seg('h2h', ['race' => 'Race finishes', 'qual' => 'Qualifying'], 'race', 'Record')) ?>
  <?php foreach (['race' => $S['h2h_race'], 'qual' => $S['h2h_qual']] as $kind => $M): ?>
    <div class="scroll"<?= UI::pane('h2h', $kind, 'race') ?>>
      <table class="tbl h2h">
        <thead><tr><th></th><?php foreach ($names as $n): ?><th class="c"><?= UI::driver($n, false, null, true) ?></th><?php endforeach ?><th class="num">Won</th></tr></thead>
        <tbody>
          <?php foreach ($names as $r):
            $won = 0; $played = 0;
            foreach ($names as $c) if ($c !== $r) { $won += $M[$r][$c]; $played += $M[$r][$c] + $M[$c][$r]; } ?>
            <tr>
              <td class="name"><?= UI::driver($r) ?></td>
              <?php foreach ($names as $c): ?>
                <?php if ($r === $c): ?><td class="self"></td>
                <?php else: $w = $M[$r][$c]; $l = $M[$c][$r]; ?>
                  <td class="cell"<?= UI::tip(['title' => "{$r} vs {$c}", 'lines' => [['value' => "{$w}–{$l}", 'label' => $kind === 'race' ? 'race finishes' : 'qualifying']]]) ?>>
                    <span class="wl"><?= $w ?><span>–<?= $l ?></span></span>
                    <span class="mini"><i style="width:<?= $w + $l ? Num::fixed($w / ($w + $l) * 100, 1) : 0 ?>%;--c:<?= UI::color(UI::slot($r)) ?>"></i></span>
                  </td>
                <?php endif ?>
              <?php endforeach ?>
              <td class="num strong"><?= $played ? Num::jsRound($won / $played * 100) . '%' : F::DASH ?></td>
            </tr>
          <?php endforeach ?>
        </tbody>
      </table>
    </div>
  <?php endforeach ?>
<?= UI::end() ?>

<?php ob_start() ?>
<form class="duel-pick" method="get" action="<?= e(Http::url($S['slug'] . '/drivers')) ?>#duel">
  <label class="sr" for="duel-a">First driver</label>
  <select id="duel-a" name="a" class="select select-sm" data-autosubmit><?= $opts($A) ?></select>
  <span class="vs">VS</span>
  <label class="sr" for="duel-b">Second driver</label>
  <select id="duel-b" name="b" class="select select-sm" data-autosubmit><?= $opts($B, $A) ?></select>
  <noscript><button class="btn btn-sm" type="submit">Compare</button></noscript>
</form>
<?php $pick = ob_get_clean() ?>
<?= UI::panel('Duel', 'Pick any two drivers to compare round by round', $pick, '', 'duel') ?>
  <div class="g2">
    <div class="scroll">
      <table class="tbl">
        <thead><tr><th><?= count($rows) ?> shared rounds</th><th class="num"><?= UI::driver($A, false, null, true) ?></th><th class="num"><?= UI::driver($B, false, null, true) ?></th></tr></thead>
        <tbody>
          <?php foreach ($battles as $label => $t): ?>
            <tr><td><?= e($label) ?></td><td class="num <?= $t[0] > $t[1] ? 'strong' : 'dim' ?>"><?= $t[0] ?></td><td class="num <?= $t[1] > $t[0] ? 'strong' : 'dim' ?>"><?= $t[1] ?></td></tr>
          <?php endforeach ?>
          <tr><td>Points</td><td class="num <?= $sA['points'] > $sB['points'] ? 'strong' : 'dim' ?>"><?= $sA['points'] ?></td><td class="num <?= $sB['points'] > $sA['points'] ? 'strong' : 'dim' ?>"><?= $sB['points'] ?></td></tr>
          <tr><td>Avg gap to team best lap</td><td class="num"><?= F::pct($sA['avg_gap_team_pct']) ?></td><td class="num"><?= F::pct($sB['avg_gap_team_pct']) ?></td></tr>
        </tbody>
      </table>
    </div>
    <div>
      <div class="scroll">
        <table class="tbl compact">
          <thead><tr><th>Round</th><th class="num">Finish</th><th class="num">Best lap gap</th><th class="num">Incidents</th></tr></thead>
          <tbody>
            <?php foreach ($rows as [$r, $x, $y]): $d = $x['best'] && $y['best'] ? $x['best'] - $y['best'] : null; ?>
              <tr>
                <td>R<?= $r['round'] ?> <?= e(F::trackTiny($r['track_short'])) ?></td>
                <td class="num"><?= F::ord($x['finish']) ?> <span class="dim">v</span> <?= F::ord($y['finish']) ?></td>
                <td class="num"<?= UI::tip(['title' => "R{$r['round']} best laps", 'lines' => [['value' => F::lap($x['best']), 'label' => $A], ['value' => F::lap($y['best']), 'label' => $B]]]) ?>>
                  <?= $d === null ? F::DASH : e(F::firstName($d < 0 ? $A : $B)) . ' ' . Num::fixed(abs($d), 3) . 's' ?></td>
                <td class="num"><?= $x['inc'] ?> <span class="dim">v</span> <?= $y['inc'] ?></td>
              </tr>
            <?php endforeach ?>
          </tbody>
        </table>
      </div>
      <p class="note">Best lap gap names whoever was quicker, and by how much.</p>
    </div>
  </div>
<?= UI::end() ?>

<?= UI::panel('Season table', 'Finishing positions are overall (including AI). Incident rate is per 10 laps completed.') ?>
  <div class="scroll">
    <table class="tbl">
      <thead><tr><th>Pos</th><th>Driver</th><th class="num">Points</th><th class="num">Wins</th><th class="num">Podiums</th><th class="num">Poles</th>
        <th class="num">Avg start</th><th class="num">Avg finish</th><th class="num">Laps led</th><th class="num">Incidents</th><th class="num">Inc / 10</th><th>Main car</th></tr></thead>
      <tbody>
        <?php foreach ($S['standings'] as $s): ?>
          <tr class="<?= $s['pos'] === 1 ? 'p1' : '' ?>">
            <td class="pos"><?= str_pad((string) $s['pos'], 2, '0', STR_PAD_LEFT) ?></td><td class="name"><?= UI::driver($s['driver']) ?></td><td class="num strong"><?= $s['points'] ?></td>
            <td class="num"><?= $s['wins'] ?></td><td class="num"><?= $s['podiums'] ?></td><td class="num"><?= $s['poles'] ?></td>
            <td class="num"><?= F::fix($s['avg_start'], 1) ?></td><td class="num"><?= F::fix($s['avg_finish'], 1) ?></td><td class="num"><?= $s['laps_led'] ?></td>
            <td class="num"><?= $s['inc'] ?></td><td class="num"><?= F::per10($s['inc_per_lap']) ?></td><td class="dim"><?= e(F::carShort((string) array_key_first($s['cars']))) ?></td>
          </tr>
        <?php endforeach ?>
      </tbody>
    </table>
  </div>
<?= UI::end() ?>

<h2 class="h2">Driver profiles</h2>
<div class="g3">
  <?php foreach ($S['standings'] as $s):
    $xs = array_values($res[$s['driver']] ?? []);
    $best = $xs; usort($best, fn($p, $q) => $p['finish'] <=> $q['finish']); $best = $best[0];
    $q = array_values(array_filter(array_column($xs, 'team_qual')));
    $h = $S['hardest_by_driver'][$s['driver']] ?? null;
    $awards = array_map(fn($w) => $w['title'], array_filter($S['awards'], fn($w) => str_contains($w['driver'], $s['driver'])));
    $cars = implode(', ', array_map(fn($c, $k) => F::carShort((string) $c) . ($k > 1 ? " ×{$k}" : ''), array_keys($s['cars']), $s['cars'])); ?>
    <div class="card dcard" style="--c:<?= UI::color($s['color']) ?>">
      <span class="dcard-band"></span>
      <div class="dcard-h"><?= UI::driver($s['driver']) ?><span class="pbadge<?= $s['pos'] === 1 ? ' p1' : '' ?>">P<?= $s['pos'] ?> · <?= $s['points'] ?> pts</span></div>
      <div class="dcard-b">
        <div class="kv">
          <?php foreach ([[$s['wins'], 'Wins'], [$s['podiums'], 'Podiums'], [F::fix($s['avg_finish'], 1), 'Avg finish'], [F::pct($s['avg_gap_team_pct']), 'Pace gap'], [F::fix($s['inc_per_race'], 1), 'Inc / race'], [$s['ai_beaten'], 'AI beaten']] as [$v, $l]): ?>
            <div><b><?= e((string) $v) ?></b><span><?= e($l) ?></span></div>
          <?php endforeach ?>
        </div>
        <ul class="facts">
          <li>Best result <strong><?= F::ord($best['finish']) ?></strong> at <?= e($trackOf[$best['round']]['track_short']) ?></li>
          <li>Average team qualifying <strong><?= $q ? Num::fixed(array_sum($q) / count($q), 1) : F::DASH ?></strong> of <?= count($S['drivers']) ?></li>
          <?php if ($h): ?><li>Worst incident rate at <strong><?= e($trackOf[$h['most_incidents']['round']]['track_short']) ?></strong> (<?= $h['most_incidents']['inc'] ?>x)</li><?php endif ?>
          <li>Cars <strong><?= e($cars) ?></strong></li>
          <?php if ($awards): ?><li>Awards <strong><?= e(implode(', ', $awards)) ?></strong></li><?php endif ?>
        </ul>
      </div>
    </div>
  <?php endforeach ?>
</div>
