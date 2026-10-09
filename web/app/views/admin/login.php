<?php
use PitWall\Auth;
use PitWall\Http;

/** @var ?string $error @var string $username */
?>
<div class="auth">
  <form class="card form" method="post" action="<?= e(Http::url('admin/login')) ?>">
    <?= Auth::field() ?>
    <span class="kicker">Restricted · race control</span>
    <h1 class="form-h">Sign in</h1>
    <?php if ($error): ?><p class="flash error" role="alert"><strong><?= e($error) ?></strong></p><?php endif ?>
    <label class="field"><span>Username</span><input class="input" name="username" autocomplete="username" required value="<?= e($username) ?>" autofocus></label>
    <label class="field"><span>Password</span><input class="input" type="password" name="password" autocomplete="current-password" required></label>
    <button class="btn btn-primary" type="submit">Sign in</button>
  </form>
</div>
