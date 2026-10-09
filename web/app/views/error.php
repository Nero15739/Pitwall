<?php
use PitWall\Http;

/** @var int $status @var string $title @var string $message */
?>
<div class="empty">
  <span class="kicker">Error <?= (int) $status ?></span>
  <h1><?= e($title) ?></h1>
  <p><?= e($message) ?></p>
  <p><a class="btn btn-primary" href="<?= e(Http::url('/')) ?>">Back to the pit wall</a></p>
</div>
