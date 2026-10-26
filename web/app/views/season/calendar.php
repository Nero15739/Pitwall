<?php
use PitWall\F;
use PitWall\Http;
use PitWall\Site;
use PitWall\UI;

/** @var array $S @var array $tracks */
$cal = Site::calendar($S);
$R = array_column($S['rounds'], null, 'round');
$next = $cal['next'];
$after = $next ? array_values(array_filter($cal['ahead'], fn($c) => $c['n'] > $next['n']))[0] ?? null : null;
$last = $cal['ahead'] ? $cal['ahead'][count($cal['ahead']) - 1] : null;
$u = fn(string $p) => e(Http::url($S['slug'] . $p));
$mapOf = fn(array $c) => $c['track_id'] !== null ? ($tracks[(string) $c['track_id']]['map'] ?? null) : null;
$pages = array_column($S['track_pages'], null, 'track_id');

// what a round still to come will look like: laps or minutes, cars, the organiser's note
$format = fn(array $c) => implode(' · ', array_filter([
    $c['laps'] ? F::plural($c['laps'], 'lap') : null,
    $c['minutes'] ? $c['minutes'] . ' min' : null,
    $c['cars'], $c['note'],
]));
// a track on the calendar that has been raced before, here or in another season
$visits = function (array $c) use ($tracks, $S): array {
    $out = [];
    foreach ($tracks[(string) $c['track_id']]['seasons'] ?? [] as $s) {
        $out[] = ['slug' => $s['slug'], 'label' => ($s['slug'] === $S['slug'] ? '' : $s['name'] . ' ') . 'R' . implode(', R', $s['rounds'])];
    }
    return $out;
};
?>
<div class="phead">
  <span class="kicker">Fixture list</span>
  <h1>Calendar</h1>
  <p>
    <mark><?= $cal['done'] ?> of <?= $cal['total'] ?> rounds raced</mark>
    <?php if ($next): ?> · next up <?= e(F::trackTiny($next['track_short'])) ?>, <?= F::date($next['date'], 'when') ?> (<?= F::until($next['date']) ?>)
    <?php elseif (!($S['scheduled'] ?? false)): ?> · the rounds still to come appear here once the season’s schedule is uploaded
    <?php else: ?> · nothing left on the schedule<?php endif ?>
  </p>
</div>

<?php if ($cal['ahead']): ?>
<div class="g4 tiles">
  <?= UI::tile('Raced', '<span>' . $cal['done'] . '</span>', 'of ' . $cal['total'], F::plural(count($cal['ahead']), 'round') . ' to go', 'hot') ?>
  <?= UI::tile('Next up', $next ? '<span title="' . e($next['track']) . '">' . e(F::trackTiny($next['track_short'])) . '</span>' : F::DASH, '',
      $next ? F::date($next['date'], 'when') : 'no dates ahead', '', true) ?>
  <?= UI::tile('Then', $after ? '<span title="' . e($after['track']) . '">' . e(F::trackTiny($after['track_short'])) . '</span>' : F::DASH, '',
      $after ? F::date($after['date'], 'when') : 'that’s the last one', '', true) ?>
  <?= UI::tile('Season finale', $last ? '<span title="' . e($last['track']) . '">' . e(F::trackTiny($last['track_short'])) . '</span>' : F::DASH, '',
      $last ? F::date($last['date'], 'long') : '', '', true) ?>
</div>
<?php endif ?>

<?= UI::panel('Season calendar', 'Every round: the ones already raced, and what’s still to come') ?>
  <ol class="fixtures">
    <?php foreach ($cal['all'] as $c):
      $done = $c['status'] === 'done';
      $r = $done ? ($R[$c['round']] ?? null) : null;
      $isNext = $next && $c['n'] === $next['n'];
      $late = !$done && F::daysUntil($c['date']) < 0;
      $before = $done ? [] : $visits($c); ?>
      <li class="fix <?= $done ? 'done' : ($isNext ? 'next' : 'ahead') ?>" id="r<?= (int) $c['n'] ?>">
        <span class="fix-n" aria-hidden="true"><?= str_pad((string) $c['n'], 2, '0', STR_PAD_LEFT) ?></span>
        <div class="fix-when">
          <b><?= F::date($c['date'], 'short') ?></b>
          <span><?= F::date($c['date'], $done ? 'weekday' : 'daytime') ?></span>
        </div>
        <div class="fix-main">
          <div class="fix-t">
            <span class="sr">Round <?= (int) $c['n'] ?>: </span>
            <?php if ($done): ?><a href="<?= $u('/tracks/' . $c['track_id']) ?>"><?= e($c['track']) ?></a><?php else: ?><?= e($c['track']) ?><?php endif ?>
          </div>
          <div class="fix-m">
            <?php if ($done && $r): ?>
              <span>Won by <?= UI::driver($r['winner'], $r['winner_ai']) ?></span>
              <?php if ($r['team_fastest']): ?><span>Team fastest <?= F::lap($r['team_fastest']['time']) ?> <?= e(F::firstName($r['team_fastest']['name'])) ?></span><?php endif ?>
              <span><?= (int) $r['laps'] ?> laps · <?= (int) $r['humans'] ?> + <?= (int) $r['ai'] ?> AI</span>
              <?php if ($c['note']): ?><span><?= e($c['note']) ?></span><?php endif ?>
            <?php else: ?>
              <?php if ($format($c) !== ''): ?><span><?= e($format($c)) ?></span><?php endif ?>
              <?php foreach ($before as $v): ?>
                <span>Raced before: <a href="<?= e(Http::url($v['slug'] . '/tracks/' . $c['track_id'])) ?>"><?= e($v['label']) ?></a></span>
              <?php endforeach ?>
              <?php if (!$before && $format($c) === ''): ?><span class="dim">New track for the team</span><?php endif ?>
            <?php endif ?>
          </div>
        </div>
        <?= UI::outline($mapOf($c), 'fix-map') ?>
        <div class="fix-s">
          <?php if ($done): ?>
            <a class="pill" href="<?= $u('/tracks/' . $c['track_id']) ?>">Results <?= UI::icon('arrow', 13) ?></a>
          <?php elseif ($late): ?>
            <span class="pill wait">Result pending</span>
          <?php else: ?>
            <span class="pill <?= $isNext ? 'hot' : '' ?>"><?= $isNext ? 'Next · ' : '' ?><?= F::until($c['date']) ?></span>
          <?php endif ?>
        </div>
      </li>
    <?php endforeach ?>
  </ol>
  <?php if (!($S['scheduled'] ?? false)): ?>
    <p class="note">No schedule has been uploaded for this season yet, so only raced rounds are listed. An admin can upload one (a small JSON file) from the admin panel.</p>
  <?php else: ?>
    <p class="note">Dates and times are shown in your own time zone. A round moves from “to come” to “raced” when its result is uploaded.</p>
  <?php endif ?>
<?= UI::end() ?>
