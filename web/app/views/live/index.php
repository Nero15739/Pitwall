<?php
use PitWall\F;
use PitWall\Http;
use PitWall\UI;

/** @var array $sessions */
?>
<div class="phead">
  <span class="kicker">Race control · live</span>
  <h1>Live sessions</h1>
  <p>Sessions logged by the <mark>Pit Wall Logger</mark> on a session admin’s PC: every incident iRacing counted, for every driver, as it happened.</p>
</div>

<?php if (!$sessions): ?>
  <?= UI::panel('Nothing logged yet') ?>
    <p class="muted" style="margin:0">Run the Pit Wall Logger during a session. With “Upload automatically when a race finishes” ticked, each race appears here when the flag drops; “Upload now” sends anything else.</p>
  <?= UI::end() ?>
<?php else: ?>
  <?= UI::panel('Sessions', count($sessions) . ' logged · newest first') ?>
    <div class="scroll">
      <table class="tbl">
        <thead><tr><th>Date</th><th>Track</th><th>Session</th><th class="num">Cars</th><th class="num">Incidents</th><th class="num">Points</th><th>Logged by</th><th>View</th></tr></thead>
        <tbody>
          <?php foreach ($sessions as $s): $href = e(Http::url('live/' . $s['id'])); ?>
            <tr>
              <td class="mono"><a href="<?= $href ?>"><?= $s['started'] ? F::date($s['started'], 'when') : F::DASH ?></a></td>
              <td><a href="<?= $href ?>"><?= e($s['track']) ?></a></td>
              <td><?= e($s['type']) ?><?= $s['finished'] ? ' <span class="dim">· finished</span>' : '' ?></td>
              <td class="num"><?= (int) $s['cars'] ?></td>
              <td class="num strong"><?= (int) $s['incidents'] ?></td>
              <td class="num"><?= (int) $s['points'] ?></td>
              <td><?= UI::driver($s['by']) ?></td>
              <td class="dim"><?= $s['view'] === 'Admin' ? 'Session admin' : e($s['view']) ?></td>
            </tr>
          <?php endforeach ?>
        </tbody>
      </table>
    </div>
  <?= UI::end() ?>
<?php endif ?>
