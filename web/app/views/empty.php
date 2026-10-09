<?php
use PitWall\Http;
?>
<div class="empty">
  <span class="kicker">Lights out soon</span>
  <h1>No races published yet</h1>
  <p>Sign in to the admin panel and upload an iRacing event result export (eventresult-*.json), or push one from the
     harvester PC with an API key. Pages appear as soon as the first race is in.</p>
  <p><a class="btn btn-primary" href="<?= e(Http::url('admin')) ?>">Open the admin panel</a></p>
</div>
