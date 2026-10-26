<?php
use PitWall\F;
use PitWall\Http;
use PitWall\Live;
use PitWall\UI;

/** @var array $d @var array $P @var ?array $map @var array $slots */
$inc = $d['incidents'];
$n = count($inc);
$lengthM = $d['track']['length_m'] ?? null;
$where = fn(?float $pct) => $pct === null ? F::DASH : F::fix($pct * 100, 1) . '%' . ($lengthM ? ' · ' . F::km($pct * $lengthM) : '');
$kinds = array_count_values(array_column($inc, 'kind'));
$busiest = $P['rows'] ? array_values($P['rows'])[0] : null;
$captured = $P['points'] + $P['missed'];

// the heat map is the track page's, fed this session's incidents
$payload = [
    'live' => true, 'track' => $d['track']['name'], 'map' => $map, 'lengthM' => $lengthM,
    'events' => $P['events'], 'all' => null, 'harvested' => [1], 'pending' => [], 'splits' => [], 'slots' => (object) $slots,
];
\PitWall\View::push('head', '<script src="' . e(Http::asset('js/track.js')) . '" defer></script>');
?>
<div class="phead">
  <span class="kicker"><a href="<?= e(Http::url('live')) ?>">Live sessions</a> · <?= e($d['session']['type']) ?> · <?= F::date($d['started'], 'when') ?></span>
  <h1><?= e($d['track']['name']) ?></h1>
  <p>Logged live by <?= e($d['recorded_by']['name']) ?><?= ($d['view'] ?? '') === 'Admin' ? ' (session admin)' : '' ?> · <?= count($d['drivers']) ?> cars, <?= $P['humans'] ?> human
    <?= $d['subsession'] ? ' · subsession ' . (int) $d['subsession'] : '' ?> · <mark>every incident point iRacing counted, as it happened</mark></p>
  <?php if (isset($d['cut'])): $late = F::plural(count($d['cut']['incidents']), 'incident') . ' (' . (int) $d['cut']['points'] . ' points)'; ?>
    <p><?= $d['cut']['mode'] === 'finish'
      ? "Each driver’s incidents count until they took the chequered flag: {$late} after that are left out."
      : 'Counted up to <strong class="mono">' . e(\PitWall\Cutoffs::clock($d['cut']['until'])) . "</strong>, when the race ended: {$late} after it are left out." ?></p>
  <?php endif ?>
</div>

<div class="g4 tiles">
  <?= UI::tile('Incidents logged', '<span>' . $n . '</span>', '', $P['points'] . ' incident points · ' . F::plural(count($P['rows']), 'driver') . ' involved', 'hot') ?>
  <?= UI::tile('Points captured', '<span>' . ($captured ? F::fix(100 * $P['points'] / $captured, 0) : '100') . '</span>', '%', $P['points'] . ' of ' . $captured . ' against iRacing’s own final counts') ?>
  <?= UI::tile('Exact location', '<span>' . ($n ? F::fix(100 * $P['exact'] / $n, 0) : F::DASH) . '</span>', '%', $P['exact'] . ' where the car left the track · ' . ($n - $P['exact']) . ' estimated (~0.8 s before the count rose)') ?>
  <?= UI::tile('Most incidents', $busiest ? UI::driver($busiest['name'], $busiest['ai'], null, true) : F::DASH, '', $busiest ? $busiest['points'] . ' points in ' . F::plural($busiest['n'], 'incident') : '') ?>
</div>

<div id="track-app" class="track-app">
  <script type="application/json" id="track-data"><?= UI::json($payload) ?></script>
  <noscript><div class="panel" style="margin-top:var(--gap)"><div class="panel-b">The track map needs JavaScript.</div></div></noscript>
</div>

<?= UI::panel('Drivers', 'What each driver’s count rose by, checked against the count iRacing finished on') ?>
  <div class="scroll">
    <table class="tbl">
      <thead><tr><th>Driver</th>
        <?php foreach (Live::KINDS as $label): ?><th class="num"><?= e($label) ?></th><?php endforeach ?>
        <th class="num">Logged points</th><th class="num" title="Already on the driver's count when logging began">Before logging</th><th class="num">iRacing’s count</th><th class="num">Accounted for</th><th class="num">Exact location</th></tr></thead>
      <tbody>
        <?php foreach ($P['rows'] as $r): ?>
          <tr>
            <td class="name"><?= UI::driver($r['name'], $r['ai']) ?></td>
            <?php foreach (Live::KINDS as $k => $label): ?><td class="num"><?= $r['kinds'][$k] ?? F::DASH ?></td><?php endforeach ?>
            <td class="num strong"><?= $r['points'] ?></td>
            <td class="num dim"><?= $r['before'] ?: F::DASH ?></td>
            <td class="num"><?= $r['final'] ?? F::DASH ?></td>
            <td class="num <?= $r['missed'] === 0 ? 'good' : '' ?>"><?= $r['missed'] === null ? F::DASH : ($r['missed'] === 0 ? 'all' : $r['missed'] . ' missed') ?></td>
            <td class="num dim"><?= $r['exact'] ?> of <?= $r['n'] ?></td>
          </tr>
        <?php endforeach ?>
      </tbody>
    </table>
  </div>
<?= UI::end() ?>

<?= UI::panel('Every incident', 'In order, on the session clock. Exact: placed where the car left the track. Estimated: where it was about 0.8 s before iRacing’s count rose.') ?>
  <div class="scroll">
    <table class="tbl compact">
      <thead><tr><th>Time</th><th class="num">Lap</th><th>Where</th><th>Driver</th><th>What</th><th class="num">Count</th><th>Location</th></tr></thead>
      <tbody>
        <?php foreach ($inc as $e): ?>
          <tr>
            <td class="mono"><?= Live::clock((float) $e['t']) ?></td>
            <td class="num"><?= $e['lap'] ?? F::DASH ?></td>
            <td class="mono"><?= $where($e['pct']) ?></td>
            <td class="name"><?= UI::driver($e['name'], (bool) $e['ai']) ?></td>
            <td><?= e(Live::KINDS[$e['kind']] ?? $e['kind']) ?> <span class="dim"><?= (int) $e['delta'] ?>x</span></td>
            <td class="num dim"><?= (int) $e['from'] ?> → <?= (int) $e['to'] ?></td>
            <td class="<?= !empty($e['off_track_at']) ? '' : 'dim' ?>"><?= !empty($e['off_track_at']) ? 'exact' : 'estimated' ?></td>
          </tr>
        <?php endforeach ?>
      </tbody>
    </table>
  </div>
<?= UI::end() ?>

<p class="note">How this is logged: the Pit Wall Logger reads iRacing’s incident count for each driver (all of them when you’re a session admin, or outside a race) and the cars’ positions 60 times a second. When a count rises it records the rise (1x off track, 2x spin or wall, 4x contact) and where the car was. Unlike the replay harvester it sees every incident, contact included; what it can’t always see is the exact spot, since leaving the track onto tarmac run-off doesn’t register as off-track.</p>
