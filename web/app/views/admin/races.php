<?php
use PitWall\Auth;
use PitWall\F;
use PitWall\Http;
use PitWall\UI;

/** @var array $seasons @var array $races @var array $harvests */
$post = fn(string $action) => e(Http::url('admin/' . $action));
?>
<div class="phead">
  <span class="kicker">Paddock</span>
  <h1>Races &amp; seasons</h1>
  <p>Move a race to another season, rename seasons, or remove files. <mark>Every change republishes the site straight away.</mark></p>
</div>

<?= UI::panel('Seasons', 'A season’s name is also its web address. Rename to change both.') ?>
  <div class="scroll">
    <table class="tbl">
      <thead><tr><th>Season</th><th>Address</th><th class="num">Races</th><th>Span</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($seasons as $s): ?>
          <tr>
            <td>
              <form class="inline" method="post" action="<?= $post('seasons/rename') ?>"><?= Auth::field() ?>
                <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                <label class="sr" for="sn<?= (int) $s['id'] ?>">Season name</label>
                <input id="sn<?= (int) $s['id'] ?>" class="input input-sm" name="name" value="<?= e($s['name']) ?>" maxlength="80" required>
                <button class="btn btn-sm" type="submit">Rename</button>
              </form>
            </td>
            <td class="mono"><a href="<?= e(Http::url($s['slug'])) ?>">/<?= e($s['slug']) ?></a></td>
            <td class="num"><?= (int) $s['races'] ?></td>
            <td class="mono dim"><?= $s['first_race'] ? F::date($s['first_race']) . ' → ' . F::date($s['last_race']) : 'empty' ?></td>
            <td class="num">
              <?php if ((int) $s['races'] === 0): ?>
                <form method="post" action="<?= $post('seasons/delete') ?>" data-confirm="Delete the empty season “<?= e($s['name']) ?>”?"><?= Auth::field() ?>
                  <input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><button class="btn btn-sm btn-danger" type="submit">Delete</button></form>
              <?php endif ?>
            </td>
          </tr>
        <?php endforeach ?>
      </tbody>
    </table>
  </div>
  <form class="row" method="post" action="<?= $post('seasons/create') ?>" style="margin-top:16px"><?= Auth::field() ?>
    <label class="field grow"><span>New empty season</span><input class="input" name="name" maxlength="80" placeholder="e.g. Asia 2027" required></label>
    <div class="field"><span>&nbsp;</span><button class="btn" type="submit">Create season</button></div>
  </form>
<?= UI::end() ?>

<?= UI::panel('Race results', count($races) . ' stored, newest first') ?>
  <?php if (!$races): ?><p class="muted">No races yet. Upload one from the dashboard.</p><?php else: ?>
  <div class="scroll">
    <table class="tbl compact">
      <thead><tr><th>Date</th><th>Track</th><th>Subsession</th><th>Replay</th><th>Season</th><th>Uploaded</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($races as $r): ?>
          <tr>
            <td class="mono"><?= F::date($r['start_time']) ?></td>
            <td><?= e($r['track']) ?></td>
            <td class="mono"><?= (int) $r['subsession'] ?></td>
            <td><?= (int) $r['harvested'] ? '<span class="pbadge p1">' . (int) $r['n_incidents'] . ' inc</span>' : '<span class="dim mono">not yet</span>' ?></td>
            <td>
              <form class="inline" method="post" action="<?= $post('races/move') ?>"><?= Auth::field() ?>
                <input type="hidden" name="subsession" value="<?= (int) $r['subsession'] ?>">
                <label class="sr" for="mv<?= (int) $r['subsession'] ?>">Season</label>
                <select id="mv<?= (int) $r['subsession'] ?>" class="select select-sm" name="season" data-autosubmit>
                  <?php foreach ($seasons as $s): ?><option value="<?= (int) $s['id'] ?>"<?= (int) $s['id'] === (int) $r['season_id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach ?>
                </select>
                <noscript><button class="btn btn-sm" type="submit">Move</button></noscript>
              </form>
            </td>
            <td class="mono dim" title="<?= e($r['uploaded_at']) ?>"><?= e(F::ago($r['uploaded_at'])) ?> · <?= e($r['uploaded_by']) ?></td>
            <td class="num">
              <form method="post" action="<?= $post('races/delete') ?>" data-confirm="Delete <?= e($r['track']) ?> (<?= (int) $r['subsession'] ?>)? The site drops it straight away."><?= Auth::field() ?>
                <input type="hidden" name="subsession" value="<?= (int) $r['subsession'] ?>">
                <button class="btn btn-sm btn-danger" type="submit" aria-label="Delete race <?= (int) $r['subsession'] ?>"><?= UI::icon('trash', 14) ?></button></form>
            </td>
          </tr>
        <?php endforeach ?>
      </tbody>
    </table>
  </div>
  <?php endif ?>
<?= UI::end() ?>

<?= UI::panel('Harvested replays', 'Crash locations and sector splits from the replay harvester') ?>
  <?php if (!$harvests): ?><p class="muted">None yet. Run the harvester on the iRacing PC; with an API key it uploads here by itself.</p><?php else: ?>
  <div class="scroll">
    <table class="tbl compact">
      <thead><tr><th>Subsession</th><th>Track</th><th class="num">Incidents</th><th class="num">Timed laps</th><th>Race</th><th>Uploaded</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($harvests as $h): ?>
          <tr>
            <td class="mono"><?= (int) $h['subsession'] ?></td>
            <td><?= e($h['track'] ?? '–') ?></td>
            <td class="num"><?= (int) $h['n_incidents'] ?></td>
            <td class="num"><?= (int) $h['n_laps'] ?></td>
            <td><?= (int) $h['matched'] ? '<span class="good mono">matched</span>' : '<span class="dim mono">waiting for result</span>' ?></td>
            <td class="mono dim"><?= e(F::ago($h['uploaded_at'])) ?> · <?= e($h['uploaded_by']) ?></td>
            <td class="num">
              <form method="post" action="<?= $post('harvests/delete') ?>" data-confirm="Delete the harvested replay for <?= (int) $h['subsession'] ?>?"><?= Auth::field() ?>
                <input type="hidden" name="subsession" value="<?= (int) $h['subsession'] ?>">
                <button class="btn btn-sm btn-danger" type="submit" aria-label="Delete harvest <?= (int) $h['subsession'] ?>"><?= UI::icon('trash', 14) ?></button></form>
            </td>
          </tr>
        <?php endforeach ?>
      </tbody>
    </table>
  </div>
  <?php endif ?>
<?= UI::end() ?>
