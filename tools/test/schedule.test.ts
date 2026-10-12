import assert from 'node:assert/strict';
import { test } from 'node:test';
import type { Round } from '../lib/types.ts';
import { buildCalendar, normalizeSchedule, when } from '../stats/schedule.ts';

const round = (n: number, date: string, track: string, id: number, config = ''): Round =>
  ({ round: n, date, track: track + (config ? ` – ${config}` : ''), track_short: track, track_id: id, laps: 14 } as Round);

test('a date stays a date and a time becomes UTC', () => {
  assert.equal(when('2026-11-02'), '2026-11-02');
  assert.equal(when('2026-11-02T19:30:00+11:00'), '2026-11-02T08:30:00Z');
  assert.equal(when('2026-11-02 19:30+1100'), '2026-11-02T08:30:00Z');
  assert.equal(when('2026-11-02T08:30:00Z'), '2026-11-02T08:30:00Z');
  assert.equal(when('2026-01-01T00:30:00+02:00'), '2025-12-31T22:30:00Z');
  assert.equal(when('2026-02-30'), null, 'no such day');
  assert.equal(when('2026-11-02T19:30'), null, 'a time needs its offset');
  assert.equal(when('next Monday'), null);
});

test('rounds are cleaned up and put in date order', () => {
  const s = normalizeSchedule([
    { date: '2026-12-01', track: '  Monza ', config: 'N/A', laps: '12', track_id: 239.0, note: '' },
    { date: '2026-11-02T19:30:00+11:00', track: 'Spa', minutes: 40, cars: 'GT3' },
  ]);
  assert.deepEqual(s.map(e => e.track), ['Spa', 'Monza']);
  assert.deepEqual(s[1], { date: '2026-12-01', track: 'Monza', config: null, track_id: 239, laps: 12, minutes: null, cars: null, note: null });
  assert.throws(() => normalizeSchedule([{ date: '2026-11-02' }]), /round 1 needs a "track"/);
  assert.throws(() => normalizeSchedule([{ track: 'Spa', date: 'soon' }]), /round 1 needs a "date"/);
});

test('scheduled rounds with a result count as raced; the rest follow, numbered on', () => {
  const rounds = [round(1, '2026-08-10T10:00:24Z', 'Hungaroring', 413), round(2, '2026-09-07T10:00:03Z', 'Brands Hatch Circuit', 145, 'Grand Prix')];
  const sched = normalizeSchedule([
    { date: '2026-08-10', track: 'Hungaroring', track_id: 413, note: 'Opener' },
    { date: '2026-09-08', track: 'brands hatch circuit' },                       // by name, a day out
    { date: '2026-10-19', track: 'Spa', track_id: 163 },
    { date: '2026-12-14', track: 'Brands Hatch Circuit' },                       // a second visit, months later
  ]);
  const cal = buildCalendar(rounds, sched, new Map([['brandshatchcircuit', 145]]));
  assert.deepEqual(cal.map(c => [c.n, c.status, c.track_short, c.track_id]), [
    [1, 'done', 'Hungaroring', 413],
    [2, 'done', 'Brands Hatch Circuit', 145],
    [3, 'upcoming', 'Spa', 163],
    [4, 'upcoming', 'Brands Hatch Circuit', 145],
  ]);
  assert.equal(cal[0].note, 'Opener');
  assert.equal(cal[0].date, '2026-08-10T10:00:24Z', 'a raced round keeps the date it was raced');
});

test('no schedule: the calendar is just the rounds raced', () => {
  const cal = buildCalendar([round(1, '2026-08-10T10:00:24Z', 'Hungaroring', 413)], [], new Map());
  assert.equal(cal.length, 1);
  assert.equal(cal[0].status, 'done');
});
