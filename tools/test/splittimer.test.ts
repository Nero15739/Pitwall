import assert from 'node:assert/strict';
import { test } from 'node:test';
import { SplitTimer, type TimedLap } from '../splittimer.ts';

/** Drive one car round a lap with a known speed profile, sampling every `dt` seconds. */
function drive(sectorTimes: number[], sectors: number[], laps: number, dt: number, startPct = 0.95) {
  const timer = new SplitTimer(sectors);
  const lapTime = sectorTimes.reduce((a, b) => a + b, 0);
  // position as a function of time: linear within each sector at that sector's speed
  const pos = (t: number) => {
    const lapIdx = Math.floor(t / lapTime);
    let r = t - lapIdx * lapTime;
    for (let k = 0; k < sectors.length; k++) {
      const len = (k + 1 < sectors.length ? sectors[k + 1] : 1) - sectors[k];
      if (r <= sectorTimes[k]) return lapIdx + sectors[k] + (r / sectorTimes[k]) * len;
      r -= sectorTimes[k];
    }
    return lapIdx + 1;
  };
  // start just before the line (as on the grid): find the time offset where pos == startPct
  const t0 = lapTime - sectorTimes[sectorTimes.length - 1] * ((1 - startPct) / (1 - sectors[sectors.length - 1]));
  const out: TimedLap[] = [];
  // the grid lap is partial, so `laps` full laps need laps + 1 line crossings
  for (let t = t0; t < t0 + (laps + 1) * lapTime; t += dt) {
    const d = pos(t);
    out.push(...timer.update(0, d - Math.floor(d), t, Math.floor(d) + 1, false));
  }
  return out;
}

test('times sectors from sparse samples (16× replay, ~0.27 s apart)', () => {
  const sectors = [0, 0.3, 0.65];
  const laps = drive([31.2, 38.4, 29.9], sectors, 3, 16 / 60);
  assert.equal(laps.length, 3, 'the partial grid lap is skipped, three full laps are timed');
  // This profile changes speed abruptly (up to 28%) exactly at each line, the worst case for
  // interpolating between samples; real cars change speed smoothly, so real error is smaller.
  const TOL = 0.035;
  for (const l of laps) {
    assert.ok(Math.abs(l.sectors[0] - 31.2) < TOL, `S1 ${l.sectors[0]}`);
    assert.ok(Math.abs(l.sectors[1] - 38.4) < TOL, `S2 ${l.sectors[1]}`);
    assert.ok(Math.abs(l.sectors[2] - 29.9) < TOL, `S3 ${l.sectors[2]}`);
    assert.ok(Math.abs(l.lap_time - 99.5) < TOL, `lap ${l.lap_time}`);
  }
});

test('a seek (time jump) drops the lap in progress instead of inventing times', () => {
  const timer = new SplitTimer([0, 0.5]);
  timer.update(1, 0.9, 0, 1, false);
  timer.update(1, 0.05, 2, 2, false);        // crossed the line
  timer.update(1, 0.4, 60, 2, false);        // 58 s gap: treated as a seek, state resets
  const out = [...timer.update(1, 0.6, 61, 2, false), ...timer.update(1, 0.02, 70, 3, false)];
  assert.equal(out.length, 0);
});

test('pit road during a lap is flagged', () => {
  const timer = new SplitTimer([0, 0.5], 100); // coarse samples here, so allow long gaps
  const out: TimedLap[] = [];
  const seq: [number, number, boolean][] = [[0.9, 0, false], [0.1, 10, false], [0.6, 50, true], [0.95, 90, false], [0.05, 99, false]];
  for (const [p, t, pit] of seq) out.push(...timer.update(2, p, t, 1, pit));
  // only one complete lap: line at ~t=5.5, half at ~45, line again at ~95.5
  assert.equal(out.length, 1);
  assert.equal(out[0].pit, true);
});
