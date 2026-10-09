<?php
use PitWall\Auth;
use PitWall\Http;
use PitWall\Paths;

/** @var ?string $error */
Auth::installToken(); // make sure the token file exists before asking for it
$where = Paths::storageIsOutside()
    ? ['pitwall-storage/INSTALL-TOKEN.txt', 'the pitwall-storage folder sits next to public_html']
    : ['storage/INSTALL-TOKEN.txt', 'the storage folder sits next to index.php'];
?>
<div class="auth">
  <form class="card form" method="post" action="<?= e(Http::url('admin/setup')) ?>">
    <?= Auth::field() ?>
    <span class="kicker">First run</span>
    <h1 class="form-h">Create the admin account</h1>
    <p class="muted">To prove this is your site, open <code class="k"><?= e($where[0]) ?></code> in Hostinger’s File Manager
      (<?= e($where[1]) ?>) and paste the token below. The file is deleted once the account exists.</p>
    <?php if ($error): ?><p class="flash error" role="alert"><strong><?= e($error) ?></strong></p><?php endif ?>
    <label class="field"><span>Setup token</span><input class="input" name="token" autocomplete="off" spellcheck="false" required></label>
    <label class="field"><span>Username</span><input class="input" name="username" autocomplete="username" required pattern="[A-Za-z0-9._\-]{3,64}" value="<?= e(Http::post('username')) ?>"></label>
    <label class="field"><span>Password <em>12+ characters</em></span><input class="input" type="password" name="password" autocomplete="new-password" minlength="12" required></label>
    <label class="field"><span>Password again</span><input class="input" type="password" name="confirm" autocomplete="new-password" minlength="12" required></label>
    <button class="btn btn-primary" type="submit">Create account</button>
  </form>
</div>
