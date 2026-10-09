/* Incident markers → hotspot density around the lap and the top clusters.
   Everything wraps at the start/finish line, so the lap is treated as a circle. */
import { HOTSPOT_BINS, type Hotspot, type HotspotSet, type HotspotSummary, type IncidentEvent } from '../lib/types.ts';
import { rnd } from '../lib/util.ts';

const B = HOTSPOT_BINS;
const SIGMA = 1.5;        // smoothing, in bins (0.75% of a lap)
const WINDOW = 4;         // a hotspot collects markers within ±4 bins (±2% of a lap)
const MIN_SEP = 9;        // peaks closer than this merge into one hotspot
const MAX_HOTSPOTS = 5;

const KERNEL = (() => {
  const r = Math.ceil(SIGMA * 3);
  const k = Array.from({ length: 2 * r + 1 }, (_, i) => Math.exp(-((i - r) ** 2) / (2 * SIGMA ** 2)));
  const s = k.reduce((a, b) => a + b, 0);
  return k.map(v => v / s);
})();

const binOf = (pct: number) => Math.min(B - 1, Math.floor((((pct % 1) + 1) % 1) * B));
const wrap = (i: number) => ((i % B) + B) % B;
/** Shortest distance between two lap positions, in laps (0..0.5). */
const lapDist = (a: number, b: number) => { const d = Math.abs(a - b) % 1; return Math.min(d, 1 - d); };

function density(events: IncidentEvent[]): number[] {
  const raw = new Array<number>(B).fill(0);
  for (const e of events) raw[binOf(e.pct)]++;
  const r = (KERNEL.length - 1) / 2;
  return raw.map((_, i) => rnd(KERNEL.reduce((acc, k, j) => acc + k * raw[wrap(i + j - r)], 0), 3));
}

/** Circular mean of lap positions (handles clusters that straddle the line). */
function circularMean(pcts: number[]): number {
  const s = pcts.reduce((a, p) => a + Math.sin(2 * Math.PI * p), 0);
  const c = pcts.reduce((a, p) => a + Math.cos(2 * Math.PI * p), 0);
  return (((Math.atan2(s, c) / (2 * Math.PI)) % 1) + 1) % 1;
}

function peaks(events: IncidentEvent[], d: number[], lengthM: number | null): Hotspot[] {
  if (!events.length) return [];
  const cands = d
    .map((v, i) => ({ v, i }))
    .filter(({ v, i }) => v > 0 && v >= d[wrap(i - 1)] && v > d[wrap(i + 1)])
    .sort((a, b) => b.v - a.v);
  const picked: number[] = [];
  for (const c of cands) {
    if (picked.every(p => Math.min(Math.abs(p - c.i), B - Math.abs(p - c.i)) >= MIN_SEP)) picked.push(c.i);
  }
  const out: Hotspot[] = [];
  for (const i of picked) {
    const centre = (i + 0.5) / B;
    const near = events.filter(e => lapDist(e.pct, centre) <= WINDOW / B);
    if (near.length < 2) continue;
    const pct = circularMean(near.map(e => e.pct));
    // spread relative to the centre so a cluster across the line still reads from → to
    const rel = near.map(e => { let x = e.pct - pct; if (x > 0.5) x -= 1; if (x < -0.5) x += 1; return x; });
    const tally = new Map<string, { name: string; n: number; team: boolean; ai: boolean }>();
    for (const e of near) {
      const t = tally.get(e.driver) ?? { name: e.driver, n: 0, team: e.team, ai: e.ai };
      t.n++;
      tally.set(e.driver, t);
    }
    out.push({
      pct: rnd(pct, 4),
      from: rnd((((pct + Math.min(...rel)) % 1) + 1) % 1, 4),
      to: rnd((((pct + Math.max(...rel)) % 1) + 1) % 1, 4),
      count: near.length,
      distance_m: lengthM ? Math.round(pct * lengthM) : null,
      drivers: [...tally.values()].sort((a, b) => b.n - a.n || a.name.localeCompare(b.name)),
    });
  }
  return out.sort((a, b) => b.count - a.count).slice(0, MAX_HOTSPOTS);
}

export function summarize(events: IncidentEvent[], lengthM: number | null): HotspotSummary {
  const team = events.filter(e => e.team);
  const dTeam = density(team), dField = density(events);
  return {
    n_team: team.length,
    n_field: events.length,
    density_team: dTeam,
    density_field: dField,
    top_team: peaks(team, dTeam, lengthM),
    top_field: peaks(events, dField, lengthM),
  };
}

export function hotspotSet(events: IncidentEvent[], lengthM: number | null): HotspotSet {
  return { ...summarize(events, lengthM), events, length_m: lengthM };
}
