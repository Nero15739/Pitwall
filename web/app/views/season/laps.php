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
$tvai = array_column($S['tracks'], 'team_vs_ai_pct', 'round');
$last = (string) $R[count($R) - 1]['round'];

// pace gap heatmap scale
$flat = array_values(array_filter(array_column($S['results'], 'gap_team_pct'), fn($v) => $v !== null));
$hmin = $flat ? min($flat) : 0;
$hmax = $flat ? max($flat) : 1;

// season average gap to the team's best in each session (relative %)
$sessions = [];
foreach ($names as $n) {
    $g = function (string $k) use ($R, $S, $n) {
        $vals = [];
        foreach ($R as $r) {
            $xs = array_values(array_filter($S['results'], fn($x) => $x['round'] === $r['round'] && $x[$k] !== null));
            $me = array_values(array_filter($xs, fn($x) => $x['driver'] === $n))[0] ?? null;
            if (!$me) continue;
            $vals[] = ($me[$k] / min(array_column($xs, $k)) - 1) * 100;
        }
        return $vals ? array_sum($vals) / count($vals) : null;
    };
    $p = $g('prac'); $q = $g('qual'); $rr = $g('best');
    $trend = $rr !== null && $p !== null ? ($rr < $p - 0.1 ? 'Finds pace on race day' : ($rr > $p + 0.1 ? 'Quicker in practice' : 'Steady across sessions')) : '';
    $sessions[] = compact('n', 'p', 'q', 'rr', 'trend');
}
$cons = array_values(array_filter($S['standings'], fn($s) => $s['avg_consistency_pct'] !== null));
usort($cons, fn($a, $b) => $a['avg_consistency_pct'] <=> $b['avg_consistency_pct']);
$u = fn(string $p) => e(Http::url($S['slug'] . $p));
// rounds where the AI field could be rated against the team
$rated = array_filter($R, fn($r) => ($r['field']['ai_rating'] ?? null) !== null);
?>
<div class="phead">
  <span class="kicker">Timing screen</span>
  <h1>Lap times</h1>
  <p>Who was quickest, by how much, and how consistent. <mark>Gaps are measured to the team’s fastest lap</mark> at each round; the fastest AI is the benchmark, and each grid’s strength is rated below.</p>
</div>

<?= UI::panel('Fastest laps by round', 'Team fastest race lap, the margin to the next team-mate, and the fastest AI lap as a benchmark') ?>
  <div class="scroll">
    <table class="tbl">
      <thead><tr><th>Rnd</th><th>Track</th><th>Team fastest</th><th class="num">Lap</th><th>Car</th><th class="num">Margin</th><th class="num">Fastest AI</th><th class="num">Team vs AI</th></tr></thead>
      <tbody>
        <?php foreach ($R as $r): $tf = $r['team_fastest']; $tv = $tvai[$r['round']] ?? null; ?>
          <tr>
            <td class="pos">R<?= $r['round'] ?></td>
            <td><a href="<?= $u('/tracks/' . $r['track_id']) ?>"><?= e($r['track_short']) ?></a></td>
            <td class="name"><?= $tf ? UI::driver($tf['name']) : F::DASH ?></td>
            <td class="num strong"><?= F::lap($tf['time'] ?? null) ?></td>
            <td class="dim"><?= e(F::carShort($tf['car'] ?? null)) ?></td>
            <td class="num"><?= ($tf['margin'] ?? null) !== null ? Num::fixed($tf['margin'], 3) . 's' : F::DASH ?></td>
            <td class="num dim"><?= F::lap($r['ai_best']) ?></td>
            <td class="num <?= $tv !== null && $tv < 0 ? 'good' : '' ?>"><?= $tv === null ? F::DASH : ($tv < 0 ? Num::fixed(abs($tv), 2) . '% faster' : Num::fixed($tv, 2) . '% slower') ?></td>
          </tr>
        <?php endforeach ?>
      </tbody>
    </table>
  </div>
<?= UI::end() ?>

<?php if ($rated): ?>
<?= UI::panel('Strength of field', 'How strong the grid was at each track. AI cars have no iRating, so theirs is estimated from how they finished against ours.',
    UI::seg('sof-round', array_combine(array_map(fn($r) => (string) $r['round'], $R), array_map(fn($r) => 'R' . $r['round'] . ' ' . F::trackTiny($r['track_short']), $R)), $last, 'Round')) ?>
  <div class="g-5-7">
    <div>
      <?php foreach ($R as $r): $fd = $r['field'] ?? null; if (!$fd) continue;
        $ladder = [];
        foreach ($S['results'] as $x) {
            if ($x['round'] !== $r['round'] || ($x['irating'] ?? null) === null) continue;
            $ladder[] = ['label' => $x['driver'], 'driver' => true, 'slot' => UI::slot($x['driver']), 'value' => $x['irating'], 'text' => F::int($x['irating']),
                'tip' => ['title' => $x['driver'], 'lines' => [['value' => F::int($x['irating']), 'label' => 'iRating'], ['value' => F::ord($x['finish']), 'label' => 'finished']]]];
        }
        foreach ($fd['groups'] as $g) {
            $ladder[] = ['label' => $g['cars'] . ' AI · beat ' . $g['beat'] . '/' . $fd['rated'], 'ai' => true, 'value' => $g['rating'], 'text' => F::approx($g['rating']),
                'tip' => ['title' => F::plural($g['cars'], 'AI car'), 'lines' => [['value' => F::approx($g['rating']), 'label' => 'estimated iRating'],
                    ['value' => $g['beat'] . ' of ' . $fd['rated'], 'label' => 'team drivers finished behind them']]]];
        }
        usort($ladder, fn($a, $b) => $b['value'] <=> $a['value']); ?>
        <div<?= UI::pane('sof-round', $r['round'], $last) ?>>
          <p class="note" style="margin:0 0 12px"><?= e($r['track']) ?> · <?= $r['humans'] ?> drivers + <?= $r['ai'] ?> AI</p>
          <?= UI::bars($ladder, 'iRating ladder: team drivers and the AI field') ?>
        </div>
      <?php endforeach ?>
    </div>
    <div>
      <div class="scroll">
        <table class="tbl compact">
          <thead><tr><th>Rnd</th><th>Track</th><th class="num" title="Strength of field over the team’s iRatings, as iRacing reports it">Team SoF</th>
            <th class="num" title="The iRating at which the AI cars’ finishes against the team would be par">AI field</th><th class="num">AI range</th>
            <th class="num" title="Strength of field with the AI cars at their estimated ratings">Whole grid</th>
            <th class="num" title="Share of AI-against-team-driver match-ups the AI car won">AI ahead</th>
            <th class="num" title="The middle AI best lap against the middle team best lap">AI pace</th></tr></thead>
          <tbody>
            <?php foreach ($R as $r): $fd = $r['field'] ?? null; if (!$fd) continue;
              $pace = $fd['ai_median_best'] && $fd['team_median_best'] ? ($fd['ai_median_best'] / $fd['team_median_best'] - 1) * 100 : null; ?>
              <tr>
                <td class="pos">R<?= $r['round'] ?></td>
                <td><a href="<?= $u('/tracks/' . $r['track_id']) ?>"><?= e(F::trackTiny($r['track_short'])) ?></a></td>
                <td class="num strong"><?= F::int($r['sof'] ?? $fd['team_sof']) ?></td>
                <td class="num strong"><?= F::approx($fd['ai_rating']) ?></td>
                <td class="num dim"><?= $fd['ai_low'] === null ? F::DASH : number_format(round($fd['ai_low'], -1)) . '–' . number_format(round($fd['ai_high'], -1)) ?></td>
                <td class="num"><?= F::approx($fd['sof_est']) ?></td>
                <td class="num"><?= $fd['pairs'] ? Num::fixed($fd['ai_wins'] / $fd['pairs'] * 100, 0) . '%' : F::DASH ?></td>
                <td class="num <?= $pace !== null && $pace > 0 ? 'good' : '' ?>"><?= $pace === null ? F::DASH : Num::fixed(abs($pace), 2) . '% ' . ($pace > 0 ? 'slower' : 'faster') ?></td>
              </tr>
            <?php endforeach ?>
          </tbody>
        </table>
      </div>
      <p class="note">iRacing publishes no rating for AI cars, so the AI numbers are estimates: the iRating a car would need for its finishes against our rated drivers to be par, on iRacing’s own rating curve. Cars are grouped by how many of us they beat. One race is a small sample, so read it as a guide to how tough the grid was, not a measurement.</p>
    </div>
  </div>
<?= UI::end() ?>
<?php endif ?>

<?= UI::panel('Round detail', 'Best race lap per driver, as a gap to the team’s fastest', UI::seg('laps-round', array_combine(
    array_map(fn($r) => (string) $r['round'], $R), array_map(fn($r) => 'R' . $r['round'] . ' ' . F::trackTiny($r['track_short']), $R)), $last, 'Round')) ?>
  <?php foreach ($R as $r):
    $all = array_values(array_filter($S['results'], fn($x) => $x['round'] === $r['round']));
    usort($all, fn($a, $b) => $a['finish'] <=> $b['finish']);
    $gaps = array_values(array_filter($all, fn($x) => $x['best'] !== null));
    usort($gaps, fn($a, $b) => $a['best'] <=> $b['best']);
    $bestOf = fn(string $k) => ($v = array_filter(array_column($all, $k), fn($x) => $x !== null)) ? min($v) : null; ?>
    <div class="g-5-7"<?= UI::pane('laps-round', $r['round'], $last) ?>>
      <?= UI::bars(array_map(fn($x) => [
          'label' => $x['driver'], 'driver' => true, 'slot' => UI::slot($x['driver']),
          'value' => $x['gap_team_s'] ?? 0, 'text' => $x['gap_team_s'] ? '+' . Num::fixed($x['gap_team_s'], 3) . 's' : 'fastest',
          'tip' => ['title' => $x['driver'], 'lines' => [['value' => F::lap($x['best']), 'label' => 'lap ' . $x['best_lap_num']], ['value' => F::carShort($x['car'])]]],
      ], $gaps), 'Gap to team fastest lap') ?>
      <div>
        <div class="scroll">
          <table class="tbl compact">
            <thead><tr><th>Driver</th><th class="num">Practice</th><th class="num">Qualifying</th><th class="num">Race best</th><th class="num">Race avg</th><th class="num">Finish</th></tr></thead>
            <tbody>
              <?php foreach ($all as $x): ?>
                <tr>
                  <td class="name"><?= UI::driver($x['driver']) ?></td>
                  <td class="num <?= $x['prac'] !== null && $x['prac'] === $bestOf('prac') ? 'strong' : '' ?>"><?= F::lap($x['prac']) ?></td>
                  <td class="num <?= $x['qual'] !== null && $x['qual'] === $bestOf('qual') ? 'strong' : '' ?>"><?= F::lap($x['qual']) ?><?= $x['qual_rank'] ? '<span class="dim"> · P' . $x['qual_rank'] . '</span>' : '' ?></td>
                  <td class="num <?= $x['best'] !== null && $x['best'] === $bestOf('best') ? 'strong' : '' ?>"><?= F::lap($x['best']) ?></td>
                  <td class="num dim"><?= F::lap($x['avg']) ?></td>
                  <td class="num"><span class="pbadge<?= $x['finish'] === 1 ? ' p1' : '' ?>"><?= F::ord($x['finish']) ?></span></td>
                </tr>
              <?php endforeach ?>
            </tbody>
          </table>
        </div>
        <p class="note">Bold marks the team’s quickest in each session. Qualifying position is overall, including AI.</p>
      </div>
    </div>
  <?php endforeach ?>
<?= UI::end() ?>

<div class="g2 g">
  <?= UI::panel('Pace gap heatmap', 'Best lap gap to the team’s fastest, in %. The hotter the cell, the further off the pace.') ?>
    <div class="scroll">
      <table class="tbl compact">
        <thead><tr><th>Driver</th><?php foreach ($R as $r): ?><th class="c" title="<?= e($r['track']) ?>">R<?= $r['round'] ?></th><?php endforeach ?><th class="num">Avg</th></tr></thead>
        <tbody>
          <?php foreach ($S['standings'] as $s): ?>
            <tr>
              <td class="name"><?= UI::driver($s['driver']) ?></td>
              <?php foreach ($R as $r): $x = $res[$s['driver']][$r['round']] ?? null; $v = $x['gap_team_pct'] ?? null; ?>
                <?= UI::heat($v, $hmin, $hmax, $v === null ? F::DASH : Num::fixed($v, 2), $x ? ['title' => $s['driver'] . ' · ' . $r['track_short'], 'lines' => [
                    ['value' => F::lap($x['best']), 'label' => F::carShort($x['car'])], ['value' => $v === null ? 'no lap' : '+' . Num::fixed($v, 2) . '%', 'label' => 'to team fastest']]] : null) ?>
              <?php endforeach ?>
              <td class="num strong"><?= F::fix($s['avg_gap_team_pct'], 2) ?></td>
            </tr>
          <?php endforeach ?>
        </tbody>
      </table>
    </div>
  <?= UI::end() ?>
  <?= UI::panel('Pace trend', 'Gap to team fastest (%) each round. Lower is faster.') ?>
    <?= UI::chart([
        'labels' => array_map(fn($r) => "R{$r['round']}", $R),
        'titles' => array_map(fn($r) => $r['track'], $R),
        'series' => array_map(fn($n) => ['name' => $n, 'slot' => UI::slot($n), 'values' => array_map(fn($r) => $res[$n][$r['round']]['gap_team_pct'] ?? null, $R)], $names),
        'fmt' => 'pace', 'sortDesc' => false, 'height' => 260, 'aria' => 'Pace gap to the team’s fastest lap by round',
    ]) ?>
  <?= UI::end() ?>
</div>

<div class="g2 g">
  <?= UI::panel('Consistency', 'How far the average race lap sits above each driver’s own best lap. Smaller is more consistent.') ?>
    <?= UI::bars(array_map(fn($s) => [
        'label' => $s['driver'], 'driver' => true, 'slot' => $s['color'], 'value' => $s['avg_consistency_pct'], 'text' => F::pct($s['avg_consistency_pct']),
        'tip' => ['title' => $s['driver'], 'lines' => [['value' => F::pct($s['avg_consistency_pct']), 'label' => 'average lap slower than best']]],
    ], $cons), 'Consistency by driver') ?>
  <?= UI::end() ?>
  <?= UI::panel('Practice → qualifying → race', 'Season average gap to the team’s best in each session') ?>
    <div class="scroll">
      <table class="tbl compact">
        <thead><tr><th>Driver</th><th class="num">Practice</th><th class="num">Qualifying</th><th class="num">Race</th><th>Trend</th></tr></thead>
        <tbody>
          <?php foreach ($sessions as $s): ?>
            <tr><td class="name"><?= UI::driver($s['n']) ?></td><td class="num"><?= F::pct($s['p']) ?></td><td class="num"><?= F::pct($s['q']) ?></td>
              <td class="num strong"><?= F::pct($s['rr']) ?></td><td class="dim"><?= e($s['trend']) ?></td></tr>
          <?php endforeach ?>
        </tbody>
      </table>
    </div>
  <?= UI::end() ?>
</div>
