<?php
use PitWall\Config;
use PitWall\Http;
use PitWall\Site;
use PitWall\UI;

/** @var string $content @var string $pageTitle */
$S ??= null;
$index ??= null;
$section ??= null;
$siteName = (string) Config::get('site_name');
$seasons = $index['seasons'] ?? [];
$slug = $S['slug'] ?? null;
?><!doctype html>
<html lang="en" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#111110">
<title><?= e($pageTitle ?? $siteName) ?><?= ($pageTitle ?? '') !== $siteName ? ' · ' . e($siteName) : '' ?></title>
<?php if (!empty($S)): ?><meta name="description" content="<?= e($S['league']) ?>: standings, lap times, incidents, track hotspots and awards."><?php endif ?>
<link rel="icon" href="<?= e(Http::asset('favicon.svg')) ?>" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@62..125,400..900&family=JetBrains+Mono:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="<?= e(Http::asset('css/pitwall.css')) ?>">
<script nonce="<?= e(Http::nonce()) ?>">(function(){var d=document.documentElement,s=null;try{s=localStorage.getItem('pitwall-theme')}catch(e){}var m=matchMedia('(prefers-color-scheme: dark)');d.dataset.theme=s||(m.matches?'dark':'light');m.addEventListener('change',function(e){var t=null;try{t=localStorage.getItem('pitwall-theme')}catch(x){}if(!t)d.dataset.theme=e.matches?'dark':'light'})})();</script>
<script src="<?= e(Http::asset('js/pitwall.js')) ?>" defer></script>
<?= \PitWall\View::slot('head') ?>
</head>
<body data-base="<?= e(Http::base()) ?>" data-version="<?= e($index['generated'] ?? '') ?>">
<a class="skip" href="#main">Skip to content</a>
<header class="mast">
  <div class="wrap mast-in">
    <a class="brand" href="<?= e(Http::url($slug ?? '/')) ?>">
      <span class="logo" aria-hidden="true">
        <svg width="26" height="26" viewBox="0 0 26 26"><path fill="#111110" d="M0 0h6.5v6.5H0zM13 0h6.5v6.5H13zM6.5 6.5H13V13H6.5zM19.5 6.5H26V13h-6.5zM0 13h6.5v6.5H0zM13 13h6.5v6.5H13zM6.5 19.5H13V26H6.5zM19.5 19.5H26V26h-6.5z"/></svg>
      </span>
      <span class="brand-t">
        <span class="brand-n"><?= e($siteName) ?></span>
        <span class="brand-s"><?= e($S['league'] ?? 'iRacing league dashboard') ?></span>
      </span>
    </a>
    <div class="mast-r">
      <?php if ($seasons): ?>
        <form action="<?= e(Http::url('/')) ?>" method="get" class="season-form">
          <label class="sr" for="season-pick">Season</label>
          <select id="season-pick" name="season" data-season-nav>
            <?php foreach ($seasons as $s): ?>
              <option value="<?= e($s['slug']) ?>"<?= $s['slug'] === $slug ? ' selected' : '' ?>><?= e($s['name']) ?> · <?= (int) $s['rounds'] ?> rnd<?= $s['rounds'] === 1 ? '' : 's' ?></option>
            <?php endforeach ?>
          </select>
          <noscript><button class="btn btn-sm" type="submit">Go</button></noscript>
        </form>
      <?php endif ?>
      <button type="button" class="iconbtn" data-theme-toggle aria-label="Switch between light and dark theme">
        <?= UI::icon('moon', 18, 'theme-light-ic') ?><?= UI::icon('sun', 18, 'theme-dark-ic') ?>
      </button>
    </div>
  </div>
  <div class="chequer" aria-hidden="true"></div>
</header>
<?php if ($slug): ?>
<nav class="tabs" aria-label="Pages">
  <div class="wrap">
    <ul>
      <?php foreach (Site::SECTIONS as $key => $label): ?>
        <li><a href="<?= e(Http::url($slug . ($key ? "/{$key}" : ''))) ?>"<?= $key === $section ? ' aria-current="page"' : '' ?>><?= e($label) ?></a></li>
      <?php endforeach ?>
    </ul>
  </div>
</nav>
<?php endif ?>

<main id="main" class="wrap main">
<?= $content ?>
</main>

<footer class="foot">
  <div class="chequer" aria-hidden="true"></div>
  <div class="wrap foot-in">
    <span><?php if (!empty($index['generated'])): ?>Data compiled <?= \PitWall\F::date($index['generated']) ?> · updates automatically<?php else: ?>No data published yet<?php endif ?></span>
    <span>Track maps © iRacing.com via iRaceHUD · AI field shown as a benchmark · <a href="<?= e(Http::url('admin')) ?>" rel="nofollow">Admin</a></span>
  </div>
</footer>
<div class="refresh" data-refresh hidden role="status">
  <span>New results are in</span>
  <button type="button" class="btn btn-sm btn-ink" data-reload>Reload</button>
</div>
<?= \PitWall\View::slot('foot') ?>
</body>
</html>
