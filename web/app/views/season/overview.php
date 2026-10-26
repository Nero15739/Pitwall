<?php
use PitWall\F;
use PitWall\Http;
use PitWall\Num;
use PitWall\Site;
use PitWall\UI;

/** @var array $S @var array $tracks */
$R = $S['rounds'];
$st = $S['standings'];
$lead = $st[0];
$byWins = $st;
usort($byWins, fn($a, $b) => $b['wins'] <=> $a['wins'] ?: ($a['avg_finish'] ?? 0) <=> ($b['avg_finish'] ?? 0));
$mostWins = $byWins[0];
$teamInc = array_sum(array_column($R, 'team_inc'));
$hard = $S['tracks'];
usort($hard, fn($a, $b) => $a['difficulty_rank'] <=> $b['difficulty_rank']);
$hardest = $hard[0];
$hardRound = array_values(array_filter($R, fn($r) => $r['round'] === $hardest['round']))[0];
$winners = implode(' · ', array_map(fn($s) => F::firstName($s['driver']) . ' ' . $s['wins'], array_filter($st, fn($s) => $s['wins'])));
$u = fn(string $p) => e(Http::url($S['slug'] . $p));
$cal = Site::calendar($S);
$next = $cal['next'];
$mapOf = fn(array $c) => $c['track_id'] !== null ? ($tracks[(string) $c['track_id']]['map'] ?? null) : null;
$format = fn(array $c) => implode(' · ', array_filter([$c['laps'] ? F::plural($c['laps'], 'lap') : null, $c['minutes'] ? $c['minutes'] . ' min' : null, $c['cars']]));
?>
<div class="phead">
  <span class="kicker"><?= e($S['league']) ?></span>
  <h1><?= e($S['season']) ?> season</h1>
  <p><mark><?= $cal['ahead'] ? count($R) . ' of ' . $cal['total'] . ' rounds raced' : e(F::plural(count($R), 'round')) ?></mark> <?= F::date($R[0]['date']) ?> → <?= F::date($R[count($R) - 1]['date']) ?> · <?= count($S['drivers']) ?> team drivers against the AI field</p>
</div>

<?php if ($next): ?>
<a class="card nextup" href="<?= $u('/calendar') ?>#r<?= (int) $next['n'] ?>">
  <span class="nextup-k">Next up · Round <?= (int) $next['n'] ?></span>
  <div class="nextup-b">
    <div class="nextup-t">
      <div class="nextup-n"><?= e($next['track']) ?></div>
      <div class="nextup-w"><?= F::date($next['date'], 'when') ?><?= $format($next) !== '' ? ' · ' . e($format($next)) : '' ?></div>
      <?php if ($next['note']): ?><div class="nextup-x"><?= e($next['note']) ?></div><?php endif ?>
    </div>
    <?= UI::outline($mapOf($next), 'nextup-map') ?>
    <div class="nextup-c"><b><?= F::until($next['date']) ?></b><span><?= F::plural(count($cal['ahead']), 'round') ?> to go <?= UI::icon('arrow', 14) ?></span></div>
  </div>
</a>
<?php endif ?>
<div class="g4 tiles">
  <?= UI::tile('Championship leader', '<span>' . e(F::firstName($lead['driver'])) . '</span>', "{$lead['points']} pts",
      isset($st[1]) ? ($lead['points'] - $st[1]['points']) . ' pts clear of ' . F::firstName($st[1]['driver']) : '', 'hot') ?>
  <?= UI::tile('Most wins', '<span>' . (int) $mostWins['wins'] . '</span>', F::firstName($mostWins['driver']), $winners ?: 'No team wins yet') ?>
  <?= UI::tile('Team incidents', '<span>' . $teamInc . '</span>', F::fix($teamInc / count($R), 1) . ' / round', 'across ' . count($S['drivers']) . ' drivers') ?>
  <?= UI::tile('Toughest track', '<a href="' . $u('/tracks/' . $hardRound['track_id']) . '" title="' . e($hardest['track']) . '">' . e(F::trackTiny($hardest['track_short'])) . '</a>',
      '', F::per10($hardest['inc_per_lap']) . ' team incidents per 10 laps') ?>
</div>

<div class="g-2-1 g">
  <?= UI::panel('Points progression', 'Cumulative championship points after each round') ?>
    <?= UI::chart([
        'labels' => array_map(fn($r) => "R{$r['round']}", $R),
        'titles' => array_map(fn($r) => $r['track'], $R),
        'series' => array_map(fn($d) => ['name' => $d['name'], 'slot' => $d['color'], 'values' => $S['progression'][$d['name']]], $S['drivers']),
        'yTitle' => 'Points', 'aria' => 'Cumulative points by round',
    ]) ?>
  <?= UI::end() ?>

  <?= UI::panel('Standings', 'Team championship') ?>
    <div class="scroll">
      <table class="tbl">
        <thead><tr><th>Pos</th><th>Driver</th><th class="num">Pts</th><th class="num">Gap</th><th class="num">Wins</th></tr></thead>
        <tbody>
          <?php foreach ($st as $s): ?>
            <tr class="<?= $s['pos'] === 1 ? 'p1' : '' ?>">
              <td class="pos"><?= str_pad((string) $s['pos'], 2, '0', STR_PAD_LEFT) ?></td>
              <td class="name"><?= UI::driver($s['driver']) ?></td>
              <td class="num strong"><?= $s['points'] ?></td>
              <td class="num dim"><?= $s['gap_to_leader'] ? '−' . $s['gap_to_leader'] : F::DASH ?></td>
              <td class="num"><?= $s['wins'] ?></td>
            </tr>
          <?php endforeach ?>
        </tbody>
      </table>
    </div>
  <?= UI::end() ?>
</div>

<h2 class="h2">Rounds</h2>
<div class="g4">
  <?php foreach ($R as $r):
    $map = $tracks[(string) $r['track_id']]['map'] ?? null;
    $band = $r['winner_ai'] ? 'var(--ai)' : UI::color(UI::slot($r['winner'])); ?>
    <a class="card rcard" href="<?= $u('/tracks/' . $r['track_id']) ?>" style="--c:<?= $band ?>">
      <span class="rcard-band"></span>
      <div class="rcard-top">
        <span class="rcard-num" aria-hidden="true"><?= str_pad((string) $r['round'], 2, '0', STR_PAD_LEFT) ?></span>
        <div style="min-width:0;position:relative">
          <div class="rcard-k">Round <?= $r['round'] ?> · <?= F::date($r['date'], false) ?></div>
          <div class="rcard-t"><?= e($r['track']) ?></div>
        </div>
        <?= UI::outline($map) ?>
      </div>
      <dl>
        <dt>Winner</dt><dd><?= UI::driver($r['winner'], $r['winner_ai']) ?></dd>
        <dt>Pole</dt><dd><?= UI::driver($r['pole'], $r['pole_ai']) ?></dd>
        <dt>Fastest</dt><dd><span class="mono"><?= F::lap($r['fastest']['time'] ?? null) ?></span> <span class="dim"><?= e($r['fastest'] ? F::firstName($r['fastest']['name']) : '') ?><?= ($r['fastest']['ai'] ?? false) ? ' (AI)' : '' ?></span></dd>
        <dt>Car</dt><dd><?= e(F::carShort($r['winner_car'])) ?></dd>
        <dt>Field</dt><dd class="mono"><?= $r['humans'] ?> + <?= $r['ai'] ?> AI · <?= $r['laps'] ?> laps</dd>
        <dt>Team inc</dt><dd class="mono"><?= $r['team_inc'] ?>x</dd>
      </dl>
      <span class="rcard-go"><span><?= $r['harvested'] ? 'Map · heat map · splits' : 'Track map · incidents' ?></span><?= UI::icon('arrow', 16) ?></span>
    </a>
  <?php endforeach ?>
</div>

<?php if ($cal['ahead']): ?>
<h2 class="h2">Still to come</h2>
<div class="g4">
  <?php foreach ($cal['ahead'] as $c): $isNext = $next && $c['n'] === $next['n']; $late = F::daysUntil($c['date']) < 0; ?>
    <a class="card rcard ahead<?= $isNext ? ' next' : '' ?>" href="<?= $u('/calendar') ?>#r<?= (int) $c['n'] ?>">
      <span class="rcard-band"></span>
      <div class="rcard-top">
        <span class="rcard-num" aria-hidden="true"><?= str_pad((string) $c['n'], 2, '0', STR_PAD_LEFT) ?></span>
        <div style="min-width:0;position:relative">
          <div class="rcard-k">Round <?= (int) $c['n'] ?> · <?= F::date($c['date'], false) ?></div>
          <div class="rcard-t"><?= e($c['track']) ?></div>
        </div>
        <?= UI::outline($mapOf($c)) ?>
      </div>
      <dl>
        <dt>When</dt><dd><?= F::date($c['date'], 'daytime') ?></dd>
        <?php if ($format($c) !== ''): ?><dt>Format</dt><dd><?= e($format($c)) ?></dd><?php endif ?>
        <?php if ($c['note']): ?><dt>Note</dt><dd title="<?= e($c['note']) ?>"><?= e($c['note']) ?></dd><?php endif ?>
      </dl>
      <span class="rcard-go"><span><?= $late ? 'Result pending' : ($isNext ? 'Next up · ' : '') . F::until($c['date']) ?></span><?= UI::icon('calendar', 16) ?></span>
    </a>
  <?php endforeach ?>
</div>
<?php endif ?>
