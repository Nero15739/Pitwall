import assert from 'node:assert/strict';
import { test } from 'node:test';
import { IncidentDetector } from '../incidentdetector.ts';

const ON = 3, OFF = 0, PIT = 1;

test('an off-track is recorded once, where the car left the circuit', () => {
  const d = new IncidentDetector();
  const seq: [number, number, number][] = [[0, 0.40, ON], [1, 0.41, ON], [2, 0.42, ON], [2.3, 0.425, OFF], [2.6, 0.428, OFF], [3, 0.43, ON], [4, 0.44, ON]];
  for (const [t, p, s] of seq) d.update(7, t, p, s, false, 3);
  assert.equal(d.incidents.length, 1);
  assert.deepEqual({ ...d.incidents[0] }, { car: 7, t: 2.3, pct: 0.42, lap: 3, kind: 'off', off: true });
});

test('wheels flicking off again right after rejoining is the same excursion', () => {
  const d = new IncidentDetector();
  const seq: [number, number, number][] = [[0, 0.1, ON], [2, 0.12, ON], [2.2, 0.121, OFF], [2.5, 0.122, ON], [2.8, 0.123, OFF], [3.5, 0.125, ON]];
  for (const [t, p, s] of seq) d.update(1, t, p, s, false, 1);
  assert.equal(d.incidents.length, 1);
});

test('rolling backwards is a spin, merged with an off-track a moment later', () => {
  const d = new IncidentDetector();
  const seq: [number, number, number][] = [[0, 0.5, ON], [2, 0.52, ON], [2.3, 0.519, ON], [2.6, 0.5185, ON], [3.5, 0.518, OFF], [6, 0.52, ON]];
  for (const [t, p, s] of seq) d.update(2, t, p, s, false, 5);
  assert.equal(d.incidents.length, 1);
  assert.equal(d.incidents[0].kind, 'spin');
  assert.equal(d.incidents[0].off, true);
  assert.equal(d.incidents[0].pct, 0.52);
});

test('crossing the line, pit road and a fresh car are not incidents', () => {
  const d = new IncidentDetector();
  const seq: [number, number, number, boolean][] = [[0, 0.98, ON, false], [1, 0.99, ON, false], [2, 0.005, ON, false], [3, 0.02, ON, false],
    [4, 0.03, PIT, true], [5, 0.029, PIT, true], [6, 0.04, OFF, true]];
  for (const [t, p, s, pit] of seq) d.update(3, t, p, s, pit, 1);
  d.update(4, 10, 0.3, OFF, false, 1); // first sight of a car already off track
  assert.equal(d.incidents.length, 0);
});
