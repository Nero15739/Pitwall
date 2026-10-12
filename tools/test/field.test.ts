import assert from 'node:assert/strict';
import { test } from 'node:test';
import { fieldStrength, sof } from '../stats/field.ts';
import type { Entry } from '../stats/race.ts';

const car = (finish: number, irating: number | null, best = 90 + finish / 10): Entry => ({
  cust_id: finish, name: `Car ${finish}`, ai: irating === null, irating, car: 'GT3', car_id: 1,
  start: finish, finish, laps: 10, laps_led: 0, best, best_lap_num: 3, avg: best + 1, inc: 0,
  points: 0, status: 'Running', interval: finish, qual: null, qual_rank: null, prac: null, helmet: null,
});

test('strength of field of equal ratings is that rating', () => {
  assert.ok(Math.abs(sof([1500, 1500, 1500])! - 1500) < 1e-9);
  assert.equal(sof([]), null);
});

test('an AI field that splits its match-ups with one rated driver is rated level with them', () => {
  // two AI cars and a 1500-rated human in the middle: one win, one loss
  const f = fieldStrength([car(1, null), car(2, 1500), car(3, null)]);
  assert.equal(f.ai_wins, 1);
  assert.equal(f.pairs, 2);
  assert.equal(f.ai_rating, 1500);
  assert.deepEqual(f.groups.map(g => [g.beat, g.cars]), [[1, 1], [0, 1]]);
  assert.ok(f.groups[0].rating > 1500 && f.groups[1].rating < 1500);
  assert.equal(f.ai_high, f.groups[0].rating);
  assert.equal(f.ai_low, f.groups[1].rating);
});

test('a field the team beat everywhere rates below the team, and stays finite', () => {
  const f = fieldStrength([car(1, 1200), car(2, 1000), car(3, null), car(4, null), car(5, null)]);
  assert.equal(f.ai_wins, 0);
  assert.ok(f.ai_rating! > 0 && f.ai_rating! < 1000, `got ${f.ai_rating}`);
  assert.ok(f.sof_est! < f.team_sof!);
  // and the mirror image: an AI sweep rates above the team without running off the scale
  const g = fieldStrength([car(1, null), car(2, null), car(3, 1200), car(4, 1000)]);
  assert.ok(g.ai_rating! > 1200 && g.ai_rating! < 12000, `got ${g.ai_rating}`);
});

test('no rated drivers or no AI cars: nothing to estimate', () => {
  assert.equal(fieldStrength([car(1, 1500), car(2, 1400)]).ai_rating, null);
  const f = fieldStrength([car(1, null), car(2, null)]);
  assert.equal(f.ai_rating, null);
  assert.equal(f.team_sof, null);
  assert.deepEqual(f.groups, []);
});
