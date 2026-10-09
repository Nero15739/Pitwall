<?php
use PitWall\F;
use PitWall\Http;
use PitWall\Num;
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
?>
<div class="phead">
  <span class="kicker"><?= e($S['league']) ?></span>
  <h1><?= e($S['season']) ?> season</h1>
  <p><mark><?= e(F::plural(count($R), 'round')) ?></mark> <?= F::date($R[0]['date']) ?> → <?= F::date($R[count($R) - 1]['date']) ?> · <?= count($S['drivers']) ?> team drivers against the AI field</p>
</div>

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
      <span class="rcard-go"><span><?= $r['harvested'] ? 'Map · hotspots · splits' : 'Track map · incidents' ?></span><?= UI::icon('arrow', 16) ?></span>
    </a>
  <?php endforeach ?>
</div>
