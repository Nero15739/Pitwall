<?php
use PitWall\Auth;
use PitWall\Http;
use PitWall\UI;

/** @var array $user @var string $overrides @var array $activity */
?>
<div class="phead">
  <span class="kicker">Setup sheet</span>
  <h1>Settings</h1>
  <p>Your sign-in, track map calibration, and the full activity log.</p>
</div>

<div class="g2 g">
  <?= UI::panel('Your account', 'Enter your current password to change anything here') ?>
    <form method="post" action="<?= e(Http::url('admin/settings/account')) ?>" class="stack"><?= Auth::field() ?>
      <label class="field"><span>Username</span><input class="input" name="username" value="<?= e($user['username']) ?>" autocomplete="username" required pattern="[A-Za-z0-9._\-]{3,64}"></label>
      <label class="field"><span>New password <em>leave blank to keep it</em></span><input class="input" type="password" name="password" autocomplete="new-password" minlength="12"></label>
      <label class="field"><span>New password again</span><input class="input" type="password" name="confirm" autocomplete="new-password"></label>
      <label class="field"><span>Current password</span><input class="input" type="password" name="current" autocomplete="current-password" required></label>
      <div><button class="btn btn-primary" type="submit">Save account</button></div>
    </form>
  <?= UI::end() ?>

  <?= UI::panel('Track map calibration', 'Fix a map whose markers sit in the wrong place: offset moves the start/finish line round the lap (0 to 1), direction 1 or -1 flips the lap direction.') ?>
    <form method="post" action="<?= e(Http::url('admin/settings/overrides')) ?>" class="stack"><?= Auth::field() ?>
      <label class="field"><span>Overrides (JSON, by track ID)</span>
        <textarea class="textarea mono" name="overrides" rows="7" spellcheck="false" placeholder='{ "250": { "offset": 0.516, "direction": 1 } }'><?= e($overrides) ?></textarea></label>
      <div><button class="btn" type="submit">Save calibration</button></div>
    </form>
    <form method="post" action="<?= e(Http::url('admin/settings/maps')) ?>" style="margin-top:18px" data-confirm="Fetch every track map again from iRaceHUD?"><?= Auth::field() ?>
      <button class="btn btn-sm" type="submit"><?= UI::icon('map', 14) ?> Re-fetch track maps</button>
    </form>
    <p class="note">Maps are iRacing’s official SVGs as mirrored by the open-source iRaceHUD project, fetched once per track.</p>
  <?= UI::end() ?>
</div>

<?= UI::panel('Activity log', 'The last 100 events') ?>
  <?= \PitWall\View::partial('admin/_activity', ['activity' => $activity]) ?>
<?= UI::end() ?>
