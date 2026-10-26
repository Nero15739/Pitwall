<?php
use PitWall\Auth;
use PitWall\Cutoffs;
use PitWall\F;
use PitWall\Http;
use PitWall\UI;

/** @var array $items from Cutoffs::review, newest race first */
$post = e(Http::url('admin/incidents/cut'));
$c = fn(?float $t) => $t === null ? F::DASH : e(Cutoffs::clock($t));
$left = fn(array $n, ?array $log) => F::plural($n['replay'], 'replay incident') . ($log ? ' and ' . F::plural($n['live'], 'live-log incident') : '');
$changes = fn(array $o) => implode(', ', array_map(fn($x) => e($x['name']) . ' ' . $x['from'] . ' → ' . $x['to'], $o));
?>
<div class="phead">
  <span class="kicker">Stewards</span>
  <h1>Incidents</h1>
  <p>After the chequered flag some drivers play around on the cool-down lap, and those incidents get counted too. Choose where each race’s
    incidents stop counting: when each driver takes the flag, or at a race time. <mark>Every change republishes the site straight away.</mark></p>
</div>

<?php if (!$items): ?><p class="muted">No races yet.</p><?php endif ?>
<?php foreach ($items as $it):
  $r = $it['race']; $sub = (int) $r['subsession']; $f = $it['finish']; $cut = $it['cut']; $log = $it['log'];
  $mode = $cut['mode'] ?? 'all';
  $now = $mode === 'finish' ? $it['by_finish'] : ($mode === 'time' ? $it['by_time'] : null);
  // plain text: the panel escapes it
  $sub_t = $r['season'] . ' R' . (int) $r['round'] . ' · ' . html_entity_decode(strip_tags(F::date($r['start_time']))) . ' · subsession ' . $sub . ($f['laps'] ? ' · ' . (int) $f['laps'] . ' laps' : '');
  $official = $log && $log['everyone'];
?>
<?= UI::panel($r['track'], $sub_t, '', 'cutoff', 'r' . $sub) ?>
  <dl class="cut-facts">
    <div><dt>Replay harvest</dt><dd><?= $it['harvest'] === null ? '<span class="dim">not harvested</span>' : F::plural($it['harvest'], 'incident') ?></dd></div>
    <div><dt>Live log</dt><dd><?= !$log ? '<span class="dim">none</span>' : F::plural($log['n'], 'incident') . ', ' . $log['points'] . ' points <span class="dim">· ' . e($log['by']) . ($log['everyone'] ? ', every driver' : ', own car only') . '</span>' ?></dd></div>
    <div><dt>Leader finished</dt><dd class="mono"><?= $c($f['leader']) ?></dd></div>
    <div><dt>Last car finished</dt><dd class="mono"><?= $c($f['last']) ?></dd></div>
  </dl>

  <p class="cut-state">
    <?php if ($mode === 'all'): ?>
      <strong>Every incident counts.</strong>
      <?php if ($it['by_finish'] && ($it['by_finish']['replay'] || $it['by_finish']['live'])): ?><?= $left($it['by_finish'], $log) ?> came after the driver had finished.<?php endif ?>
    <?php else: ?>
      <strong><?= $mode === 'finish' ? 'Each driver’s incidents stop when they take the chequered flag.' : 'Counting incidents up to <span class="mono">' . $c($cut['until']) . '</span>.' ?></strong>
      Left out: <?= $left($now, $log) ?>.
      <?php if ($cut['official']): ?><?= $now['official'] ? 'Official incident points: ' . $changes($now['official']) . '.' : '<span class="dim">Official incident points: nothing to take off.</span>' ?><?php endif ?>
      <span class="dim">Set by <?= e(preg_replace('/^admin:/', '', $cut['by'])) ?> <?= e(F::ago($cut['at'])) ?>.</span>
    <?php endif ?>
  </p>

  <form class="cut-form" method="post" action="<?= $post ?>"><?= Auth::field() ?>
    <input type="hidden" name="subsession" value="<?= $sub ?>">
    <fieldset class="cut-modes">
      <legend class="sr">Where this race’s incidents stop counting</legend>
      <label class="check"><input type="radio" name="mode" value="all"<?= $mode === 'all' ? ' checked' : '' ?>><span>Count every incident</span></label>
      <label class="check"><input type="radio" name="mode" value="finish"<?= $mode === 'finish' ? ' checked' : '' ?><?= $it['has_laps'] ? '' : ' disabled' ?>>
        <span>Stop each driver’s incidents when they take the chequered flag
          <em><?= $it['by_finish'] ? 'leaves out ' . $left($it['by_finish'], $log) . ': anything on a lap after the laps they completed' : 'needs the race result' ?></em></span></label>
      <div class="check cut-time">
        <input type="radio" name="mode" value="time" id="t<?= $sub ?>"<?= $mode === 'time' ? ' checked' : '' ?>>
        <label for="t<?= $sub ?>">Stop all incidents at</label>
        <label class="sr" for="u<?= $sub ?>">Race time, m:ss</label>
        <input class="input input-sm mono" id="u<?= $sub ?>" name="until" value="<?= $mode === 'time' ? $c($cut['until']) : '' ?>" placeholder="m:ss" inputmode="decimal" autocomplete="off" pattern="[0-9:.]+" data-pick="t<?= $sub ?>">
        <?php if ($f['leader'] !== null): ?>
          <span class="dim">or</span>
          <button class="btn btn-sm" type="submit" name="preset" value="<?= e((string) $f['leader']) ?>" formnovalidate>Leader finished <?= $c($f['leader']) ?></button>
          <?php if ($f['last'] !== null && $f['last'] > $f['leader']): ?>
            <button class="btn btn-sm" type="submit" name="preset" value="<?= e((string) $f['last']) ?>" formnovalidate>Last car finished <?= $c($f['last']) ?></button>
          <?php endif ?>
        <?php endif ?>
      </div>
    </fieldset>
    <label class="check">
      <input type="checkbox" name="official" value="1"<?= $cut && $cut['official'] ? ' checked' : '' ?><?= $official ? '' : ' disabled' ?>>
      <span>Also take the points counted after the cut off the official incident totals
        <?php if (!$official): ?><em>needs a live log that saw every driver (recorded by a session admin)</em>
        <?php elseif (!$cut || !$cut['official']): $o = ($now ?? $it['by_finish'])['official'] ?? []; ?><em><?= $o ? ($now ? 'would change: ' : 'with each driver’s finish, would change: ') . $changes($o) : 'nothing to take off yet' ?></em><?php endif ?>
      </span>
    </label>
    <div><button class="btn btn-primary" type="submit">Save</button></div>
  </form>

  <?php if ($it['events']): ?>
    <details class="more cut-list">
      <summary>Incidents around the finish (<?= count($it['events']) ?>)</summary>
      <div class="scroll">
        <table class="tbl compact">
          <thead><tr><th>Time</th><th class="num">Lap</th><th>Driver</th><th>What</th><th>Seen in</th><th></th></tr></thead>
          <tbody>
            <?php $marked = ['leader' => false, 'last' => false];
            foreach ($it['events'] as $e):
              foreach (['leader' => 'Leader finished', 'last' => 'Last car finished'] as $k => $label):
                if (!$marked[$k] && $f[$k] !== null && $e['t'] > $f[$k] && !($k === 'last' && $f['last'] <= $f['leader'])): $marked[$k] = true; ?>
                  <tr class="cut-mark"><td class="mono"><?= $c($f[$k]) ?></td><td colspan="5"><?= $label ?></td></tr>
            <?php endif; endforeach ?>
              <tr class="<?= $e['out'] ? 'out' : '' ?>">
                <td class="mono"><?= e(Cutoffs::clock($e['t'])) ?></td>
                <td class="num"><?= isset($e['lap']) ? e($e['lap']) : F::DASH ?></td>
                <td class="name"><?= UI::driver($e['name'], $e['ai']) ?></td>
                <td><?= e($e['what']) ?><?= $e['points'] !== null ? ' <span class="dim">' . $e['points'] . 'x</span>' : '' ?><?= $e['done'] ? ' <span class="dim">· after finishing</span>' : '' ?></td>
                <td class="dim"><?= e($e['from']) ?></td>
                <td class="num"><?= $e['out'] ? '<span class="pbadge">left out</span>' : '' ?></td>
              </tr>
            <?php endforeach ?>
          </tbody>
        </table>
      </div>
    </details>
  <?php elseif ($it['harvest'] === null && !$log): ?>
    <p class="note">No replay harvest or live log for this race yet, so there are no incidents to cut.</p>
  <?php endif ?>
<?= UI::end() ?>
<?php endforeach ?>

<p class="note">The cut applies to the replay harvest (crash heat maps, the off/spin counts and the awards built on them) and to the race’s live log on /live.
  Official incident points come from iRacing’s race export, which only has each driver’s final total, so they can only be corrected where a live log
  recorded by a session admin shows which points came after the cut.</p>
