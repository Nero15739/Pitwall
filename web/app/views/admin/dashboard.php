<?php
use PitWall\Auth;
use PitWall\Config;
use PitWall\F;
use PitWall\Http;
use PitWall\UI;

/** @var array $seasons @var array $races @var array $harvests @var int $keys @var ?array $index @var array $activity */
$harvested = count(array_filter($races, fn($r) => (int) $r['harvested'] === 1));
$pending = array_values(array_filter($races, fn($r) => (int) $r['harvested'] === 0));
$orphans = array_values(array_filter($harvests, fn($h) => (int) $h['matched'] === 0));
$maxMb = min((int) Config::get('max_upload_mb', 10), (int) ini_get('upload_max_filesize') ?: 25);
?>
<div class="phead">
  <span class="kicker">Race control</span>
  <h1>Dashboard</h1>
  <p>Upload iRacing results and harvested replays here, or push them from the harvester PC with an API key. <mark>Pages update the moment a file lands.</mark></p>
</div>

<div class="g4 tiles">
  <?= UI::tile('Seasons', '<span>' . count($seasons) . '</span>', '', $seasons ? implode(' · ', array_map(fn($s) => $s['name'], array_slice($seasons, 0, 3))) : 'none yet') ?>
  <?= UI::tile('Races', '<span>' . count($races) . '</span>', '', $races ? 'latest ' . F::ago($races[0]['uploaded_at']) : 'upload one below', 'hot') ?>
  <?= UI::tile('Replays harvested', '<span>' . $harvested . '</span>', 'of ' . count($races), count($pending) ? count($pending) . ' still to harvest' : 'all caught up') ?>
  <?= UI::tile('API keys', '<span>' . $keys . '</span>', 'active', $keys ? 'see API keys' : 'create one to push from your PC') ?>
</div>

<div class="g-3-2 g">
  <?= UI::panel('Upload files', 'Event results (eventresult-*.json from iRacing) and harvested replays (incidents-*.json). Pick several at once; each is recognised automatically.') ?>
    <form method="post" action="<?= e(Http::url('admin/upload')) ?>" enctype="multipart/form-data" class="stack">
      <?= Auth::field() ?>
      <label class="drop">
        <input type="file" name="files[]" multiple accept=".json,.gz,application/json" required>
        <span class="drop-ic"><?= UI::icon('upload', 28) ?></span>
        <span class="drop-t">Drop JSON files here or click to choose</span>
        <span class="drop-s">Up to <?= $maxMb ?> MB each · gzip accepted</span>
        <ul class="drop-list"></ul>
      </label>
      <div class="row">
        <label class="field grow"><span>Season for race results</span>
          <select class="select" name="season">
            <option value="">Auto: match the league season, or a re-uploaded race’s current season</option>
            <?php foreach ($seasons as $s): ?><option value="<?= e($s['name']) ?>"><?= e($s['name']) ?> (<?= (int) $s['races'] ?> races)</option><?php endforeach ?>
            <option value="__new">New season…</option>
          </select>
        </label>
        <label class="field grow"><span>New season name <em>when “New season…” is picked</em></span><input class="input" name="new_season" maxlength="80" placeholder="e.g. Europe 2027"></label>
      </div>
      <div><button class="btn btn-primary" type="submit"><?= UI::icon('upload', 16) ?> Upload and publish</button></div>
    </form>
  <?= UI::end() ?>

  <?= UI::panel('Published data', $index ? 'Compiled ' . F::ago($index['generated']) : 'Nothing published yet') ?>
    <?php if ($index): ?>
      <ul class="facts">
        <?php foreach ($index['seasons'] as $s): ?>
          <li><a href="<?= e(Http::url($s['slug'])) ?>"><strong><?= e($s['name']) ?></strong></a> · <?= (int) $s['rounds'] ?> rounds · leader <?= e($s['leader'] ?? '–') ?></li>
        <?php endforeach ?>
      </ul>
      <?php foreach ($index['errors'] as $err): ?><p class="flash error"><?= e($err) ?></p><?php endforeach ?>
    <?php endif ?>
    <form method="post" action="<?= e(Http::url('admin/compile')) ?>" style="margin-top:16px"><?= Auth::field() ?>
      <button class="btn" type="submit"><?= UI::icon('refresh', 16) ?> Rebuild now</button></form>
    <p class="note">Rebuilding is automatic after every change. Use this after editing track overrides or updating the site’s code.</p>
  <?= UI::end() ?>
</div>

<div class="g2 g">
  <?= UI::panel('Replays to harvest', $pending ? 'These races have no crash locations or splits yet' : 'Every race has its replay harvested') ?>
    <?php if ($pending): ?>
      <div class="scroll">
        <table class="tbl compact">
          <thead><tr><th>Race</th><th>Season</th><th>On the iRacing PC</th></tr></thead>
          <tbody>
            <?php foreach (array_slice($pending, 0, 12) as $r): ?>
              <tr><td><?= e($r['track']) ?><br><span class="dim mono"><?= F::date($r['start_time']) ?></span></td><td><?= e($r['season']) ?></td>
                <td><code class="k">npm run harvest -- --open <?= (int) $r['subsession'] ?></code></td></tr>
            <?php endforeach ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <p class="muted">Nothing waiting. New races show up here until their replay is harvested.</p>
    <?php endif ?>
    <?php if ($orphans): ?>
      <p class="note"><?= count($orphans) ?> harvested replay(s) are waiting for their race result to be uploaded: <?= e(implode(', ', array_map(fn($h) => $h['subsession'], $orphans))) ?>.</p>
    <?php endif ?>
  <?= UI::end() ?>

  <?= UI::panel('Recent activity', 'Uploads, changes and sign-ins') ?>
    <?= \PitWall\View::partial('admin/_activity', ['activity' => $activity]) ?>
  <?= UI::end() ?>
</div>
