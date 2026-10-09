import assert from 'node:assert/strict';
import { test } from 'node:test';
import type { IncidentEvent } from '../lib/types.ts';
import { summarize } from '../stats/hotspots.ts';

const ev = (pct: number, driver = 'A', team = true): IncidentEvent =>
  ({ pct, driver, team, ai: !team, lap: 1, t: 0, kind: 'off', off: true, round: 1 });

test('clusters across the start/finish line stay one hotspot', () => {
  const s = summarize([ev(0.995), ev(0.998), ev(0.002, 'B'), ev(0.004), ev(0.5)], 5000);
  assert.equal(s.top_team.length, 1);
  const h = s.top_team[0];
  assert.equal(h.count, 4);
  assert.ok(h.pct > 0.99 || h.pct < 0.01, `centre ${h.pct} should sit on the line`);
  assert.equal(h.from, 0.995);
  assert.equal(h.to, 0.004);
  assert.deepEqual(h.drivers.map(d => d.name), ['A', 'B']);
});

test('team and field densities are separate and sum to their counts', () => {
  const s = summarize([ev(0.3), ev(0.31), ev(0.3, 'AI car', false)], null);
  assert.equal(s.n_team, 2);
  assert.equal(s.n_field, 3);
  const total = (d: number[]) => d.reduce((a, b) => a + b, 0);
  assert.ok(Math.abs(total(s.density_team) - 2) < 0.01);
  assert.ok(Math.abs(total(s.density_field) - 3) < 0.01);
  assert.equal(s.top_field[0].count, 3);
  assert.equal(s.top_field[0].distance_m, null);
});
