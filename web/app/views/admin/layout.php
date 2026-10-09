<?php
use PitWall\Auth;
use PitWall\Config;
use PitWall\Http;
use PitWall\UI;

/** @var string $content @var ?array $user @var array $flash @var string $route */
$tabs = ['admin/dashboard' => ['admin', 'Dashboard'], 'admin/races' => ['admin/races', 'Races'], 'admin/keys' => ['admin/keys', 'API keys'], 'admin/settings' => ['admin/settings', 'Settings']];
?><!doctype html>
<html lang="en" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($pageTitle) ?> · <?= e(Config::get('site_name')) ?></title>
<link rel="icon" href="<?= e(Http::asset('favicon.svg')) ?>" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@62..125,400..900&family=JetBrains+Mono:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="<?= e(Http::asset('css/pitwall.css')) ?>">
<script nonce="<?= e(Http::nonce()) ?>">(function(){var d=document.documentElement,s=null;try{s=localStorage.getItem('pitwall-theme')}catch(e){}d.dataset.theme=s||(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light')})();</script>
<script src="<?= e(Http::asset('js/pitwall.js')) ?>" defer></script>
</head>
<body class="admin" data-base="<?= e(Http::base()) ?>">
<header class="mast">
  <div class="wrap mast-in">
    <a class="brand" href="<?= e(Http::url('admin')) ?>">
      <span class="logo" aria-hidden="true"><svg width="26" height="26" viewBox="0 0 26 26"><path fill="#111110" d="M0 0h6.5v6.5H0zM13 0h6.5v6.5H13zM6.5 6.5H13V13H6.5zM19.5 6.5H26V13h-6.5zM0 13h6.5v6.5H0zM13 13h6.5v6.5H13zM6.5 19.5H13V26H6.5zM19.5 19.5H26V26h-6.5z"/></svg></span>
      <span class="brand-t"><span class="brand-n"><?= e(Config::get('site_name')) ?></span><span class="brand-s">Race control · admin</span></span>
    </a>
    <div class="mast-r">
      <a class="iconbtn" href="<?= e(Http::url('/')) ?>" title="View the site" aria-label="View the site"><?= UI::icon('external', 18) ?></a>
      <button type="button" class="iconbtn" data-theme-toggle aria-label="Switch between light and dark theme"><?= UI::icon('moon', 18, 'theme-light-ic') ?><?= UI::icon('sun', 18, 'theme-dark-ic') ?></button>
      <?php if ($user): ?>
        <form method="post" action="<?= e(Http::url('admin/logout')) ?>"><?= Auth::field() ?>
          <button class="iconbtn" type="submit" title="Sign out <?= e($user['username']) ?>" aria-label="Sign out"><?= UI::icon('logout', 18) ?></button></form>
      <?php endif ?>
    </div>
  </div>
  <div class="chequer" aria-hidden="true"></div>
</header>
<?php if ($user): ?>
<nav class="tabs" aria-label="Admin pages">
  <div class="wrap"><ul>
    <?php foreach ($tabs as $view => [$href, $label]): ?>
      <li><a href="<?= e(Http::url($href)) ?>"<?= $route === $view ? ' aria-current="page"' : '' ?>><?= e($label) ?></a></li>
    <?php endforeach ?>
  </ul></div>
</nav>
<?php endif ?>
<main id="main" class="wrap main">
  <?php foreach ($flash as $f): ?>
    <div class="flash <?= e($f['type']) ?>" role="<?= $f['type'] === 'error' ? 'alert' : 'status' ?>">
      <strong><?= e($f['text']) ?></strong>
      <?php if ($f['lines']): ?><ul><?php foreach ($f['lines'] as $l): ?><li><?= e($l) ?></li><?php endforeach ?></ul><?php endif ?>
    </div>
  <?php endforeach ?>
  <?= $content ?>
</main>
<footer class="foot"><div class="chequer" aria-hidden="true"></div>
  <div class="wrap foot-in"><span>Pit Wall <?= e(PITWALL_VERSION) ?> · PHP <?= e(PHP_VERSION) ?></span><span><?= $user ? 'Signed in as ' . e($user['username']) : 'Admin area' ?></span></div>
</footer>
</body>
</html>
