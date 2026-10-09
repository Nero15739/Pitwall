<?php
use PitWall\UI;

/** @var array $S */
$colorFor = function (string $who): string {
    $first = explode(' vs ', $who)[0];
    return UI::color(UI::slot($first));
};
?>
<div class="phead">
  <span class="kicker">Podium ceremony</span>
  <h1>Season awards</h1>
  <p>The numbers behind the bragging rights, <mark>all computed from the race results</mark>.</p>
</div>

<div class="g3">
  <?php foreach ($S['awards'] as $a): ?>
    <article class="card award" style="--c:<?= $colorFor($a['driver']) ?>">
      <header class="award-h">
        <span class="award-ic"><?= UI::icon($a['icon'], 24) ?></span>
        <span class="award-t"><?= e($a['title']) ?></span>
      </header>
      <div class="award-b">
        <div class="award-v"><?= e($a['value']) ?></div>
        <div class="award-d">
          <?php if (str_contains($a['driver'], ' vs ')): [$x, $y] = explode(' vs ', $a['driver'], 2); ?>
            <?= UI::driver($x) ?> <span class="dim">vs</span> <?= UI::driver($y) ?>
          <?php else: ?>
            <?= UI::driver($a['driver']) ?>
          <?php endif ?>
        </div>
        <p class="award-x"><?= e($a['detail']) ?></p>
      </div>
    </article>
  <?php endforeach ?>
</div>
