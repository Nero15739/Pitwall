<?php
use PitWall\F;

/** @var array $activity */
?>
<?php if (!$activity): ?>
  <p class="muted">Nothing yet.</p>
<?php else: ?>
  <div class="scroll">
    <table class="tbl compact">
      <thead><tr><th>When</th><th>Who</th><th>What</th></tr></thead>
      <tbody>
        <?php foreach ($activity as $a): ?>
          <tr>
            <td class="mono dim" title="<?= e($a['at']) ?>"><?= e(F::ago($a['at'])) ?></td>
            <td class="mono"><?= e($a['actor']) ?></td>
            <td><strong class="mono"><?= e($a['action']) ?></strong> <span class="dim"><?= e($a['detail'] ?? '') ?></span><?= $a['ip'] ? ' <span class="dim mono">· ' . e($a['ip']) . '</span>' : '' ?></td>
          </tr>
        <?php endforeach ?>
      </tbody>
    </table>
  </div>
<?php endif ?>
