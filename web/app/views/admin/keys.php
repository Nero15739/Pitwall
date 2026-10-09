<?php
use PitWall\Auth;
use PitWall\F;
use PitWall\Http;
use PitWall\UI;

/** @var array $keys @var ?array $newKey */
$api = Http::absoluteUrl('api/v1');
?>
<div class="phead">
  <span class="kicker">Telemetry link</span>
  <h1>API keys</h1>
  <p>A key lets the harvester PC (or any script) push results and replays without signing in. <mark>Only a fingerprint of each key is stored</mark>, so a key is shown once, when you create it.</p>
</div>

<?php if ($newKey): ?>
  <div class="flash ok newkey" role="status">
    <strong>New key for “<?= e($newKey['name']) ?>”. Copy it now: it won’t be shown again.</strong>
    <div class="row" style="margin-top:10px">
      <input class="input grow mono" id="new-key" value="<?= e($newKey['key']) ?>" readonly aria-label="New API key">
      <button class="btn btn-ink" type="button" data-copy="new-key"><?= UI::icon('copy', 16) ?> Copy</button>
    </div>
    <p class="note">On the iRacing PC, save it as the only line of a file called <code class="k">.pitwall-key</code> in the Iracing-Seasons folder, or set the environment variable <code class="k">PITWALL_API_KEY</code>.</p>
  </div>
<?php endif ?>

<div class="g-3-2 g">
  <?= UI::panel('Keys', $keys ? count(array_filter($keys, fn($k) => !$k['revoked_at'])) . ' active' : 'None yet') ?>
    <?php if ($keys): ?>
      <div class="scroll">
        <table class="tbl compact">
          <thead><tr><th>Name</th><th>Key</th><th>Last used</th><th class="num">Calls</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($keys as $k): ?>
              <tr<?= $k['revoked_at'] ? ' class="dim"' : '' ?>>
                <td class="strong"><?= e($k['name']) ?></td>
                <td class="mono"><?= e($k['prefix']) ?>…</td>
                <td class="mono"><?= e(F::ago($k['last_used_at'])) ?><?= $k['last_ip'] ? ' · ' . e($k['last_ip']) : '' ?></td>
                <td class="num"><?= (int) $k['uses'] ?></td>
                <td class="num">
                  <?php if (!$k['revoked_at']): ?>
                    <form method="post" action="<?= e(Http::url('admin/keys/revoke')) ?>" data-confirm="Revoke “<?= e($k['name']) ?>”? Anything using it stops working."><?= Auth::field() ?>
                      <input type="hidden" name="id" value="<?= (int) $k['id'] ?>"><button class="btn btn-sm btn-danger" type="submit">Revoke</button></form>
                  <?php else: ?>
                    <form method="post" action="<?= e(Http::url('admin/keys/delete')) ?>"><?= Auth::field() ?>
                      <input type="hidden" name="id" value="<?= (int) $k['id'] ?>"><button class="btn btn-sm" type="submit">Remove</button></form>
                  <?php endif ?>
                </td>
              </tr>
            <?php endforeach ?>
          </tbody>
        </table>
      </div>
    <?php endif ?>
    <form class="row" method="post" action="<?= e(Http::url('admin/keys/create')) ?>" style="margin-top:16px"><?= Auth::field() ?>
      <label class="field grow"><span>New key name</span><input class="input" name="name" maxlength="100" placeholder="e.g. Harvester PC" required></label>
      <div class="field"><span>&nbsp;</span><button class="btn btn-primary" type="submit"><?= UI::icon('key', 16) ?> Create key</button></div>
    </form>
  <?= UI::end() ?>

  <?= UI::panel('From the iRacing PC', 'The repo’s tools push for you') ?>
    <ol class="steps">
      <li>Add the site to <code class="k">pitwall.config.json</code>: <code class="k">"site": "<?= e(Http::absoluteUrl('')) ?>"</code></li>
      <li>Save the key in <code class="k">.pitwall-key</code> (git-ignored).</li>
      <li><code class="k">npm run push -- --all</code> sends every result and replay you have; after that the watcher and the harvester push new files themselves.</li>
      <li><code class="k">npm run push -- --status</code> lists races still waiting for a replay harvest.</li>
    </ol>
  <?= UI::end() ?>
</div>

<?= UI::panel('API reference', 'Send the key as “Authorization: Bearer <key>”. Bodies are the JSON files as-is; gzip them with “Content-Encoding: gzip” if you like.') ?>
  <div class="scroll">
    <table class="tbl compact">
      <thead><tr><th>Call</th><th>Does</th></tr></thead>
      <tbody>
        <tr><td class="mono">GET /api/v1/health</td><td>Public. Site version and when data was last published.</td></tr>
        <tr><td class="mono">GET /api/v1/status</td><td>Seasons, races, and which replays still need harvesting.</td></tr>
        <tr><td class="mono">POST /api/v1/results?season=Name</td><td>Store an iRacing event result. <span class="dim">Leave out <code class="k">season</code> to match by league season.</span></td></tr>
        <tr><td class="mono">POST /api/v1/incidents</td><td>Store a harvested replay (incidents-&lt;subsession&gt;.json).</td></tr>
        <tr><td class="mono">POST /api/v1/compile</td><td>Rebuild the published data. Uploads do this themselves unless you add <code class="k">?compile=0</code>.</td></tr>
      </tbody>
    </table>
  </div>
  <pre class="code">curl -X POST "<?= e($api) ?>/results?season=Europe" \
  -H "Authorization: Bearer $PITWALL_API_KEY" \
  -H "Content-Type: application/json" \
  --data-binary @eventresult-89139124-Nurburg-GP.json</pre>
  <pre class="code">Invoke-RestMethod -Method Post -Uri "<?= e($api) ?>/incidents" `
  -Headers @{ Authorization = "Bearer $env:PITWALL_API_KEY" } `
  -ContentType "application/json" -InFile "data\incidents\incidents-89139124.json"</pre>
<?= UI::end() ?>
