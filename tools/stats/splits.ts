/* Sector splits from a replay's fast-forward pass → per-driver bests, averages, theoretical
   laps, and a plain-language analysis of where each driver gains and loses time. */
import type {
  DriverSplitAnalysis, DriverSplits, HarvestFile, RoundSplits, SplitAnalysis, SplitInsight,
} from '../../src/lib/shared/types.ts';
import { mean, rnd, sum } from '../lib/util.ts';
import type { Race } from './race.ts';

const CLEAN_WITHIN = 1.07;   // laps within 107% of the driver's best count toward averages
const FASTER_THAN_OFFICIAL = 0.1; // a lap this much quicker than iRacing's official best was cut or mistimed

const first = (n: string) => n.split(' ')[0];
const s3 = (x: number) => `${x.toFixed(3)}s`;
const lapTime = (t: number) => { const m = Math.floor(t / 60); return `${m}:${(t - m * 60).toFixed(3).padStart(6, '0')}`; };
const minOf = (xs: (number | null)[]) => { const v = xs.filter((x): x is number => x != null); return v.length ? Math.min(...v) : null; };

export function roundSplits(
  round: number, race: Race, splits: NonNullable<HarvestFile['splits']>, incidents: HarvestFile['incidents'] = [],
): RoundSplits | null {
  const n = splits.sectors.length;
  const entries = new Map(race.entries.map(e => [e.cust_id, e]));
  const byDriver = new Map<number, typeof splits.laps>();
  for (const l of splits.laps) {
    if (l.sectors.length !== n || l.sectors.some(s => !(s > 0))) continue;
    byDriver.set(l.cust_id, [...(byDriver.get(l.cust_id) ?? []), l]);
  }
  const incByDriver = new Map<number, HarvestFile['incidents']>();
  for (const i of incidents) incByDriver.set(i.cust_id, [...(incByDriver.get(i.cust_id) ?? []), i]);
  const sectorOf = (pct: number) => splits.sectors.reduce((k, s, j) => (pct >= s ? j : k), 0);

  const all: DriverSplits[] = [];
  for (const [cid, laps] of byDriver) {
    const e = entries.get(cid);
    if (!e) continue;
    const timed = laps.filter(l => !l.pit && !(e.best && l.lap_time < e.best - FASTER_THAN_OFFICIAL));
    if (!timed.length) continue;
    // a sector where the car left the track or spun isn't a fair time (a cut chicane is quicker)
    const dirty = new Map(timed.map(l => {
      const during = l.start == null ? [] : (incByDriver.get(cid) ?? []).filter(i => i.t >= l.start && i.t <= l.start + l.lap_time);
      return [l, new Set(during.map(i => sectorOf(i.pct)))] as const;
    }));
    const bestLap = Math.min(...timed.map(l => l.lap_time));
    const clean = timed.filter(l => l.lap_time <= bestLap * CLEAN_WITHIN && dirty.get(l)!.size === 0);
    const best = Array.from({ length: n }, (_, k) => {
      const fair = timed.filter(l => !dirty.get(l)!.has(k)).map(l => l.sectors[k]);
      return fair.length ? rnd(Math.min(...fair), 3) : null;
    });
    all.push({
      driver: e.name, team: !e.ai, ai: e.ai,
      best,
      avg: Array.from({ length: n }, (_, k) => rnd(mean(clean.map(l => l.sectors[k])), 3)),
      best_lap: rnd(bestLap, 3),
      theoretical: best.every(b => b != null) ? rnd(sum(best as number[]), 3) : null,
      clean_laps: clean.length,
    });
  }
  const team = all.filter(d => d.team).sort((a, b) => (a.theoretical ?? 1e9) - (b.theoretical ?? 1e9));
  if (!team.length) return null;
  const ai = all.filter(d => d.ai).sort((a, b) => (a.theoretical ?? 1e9) - (b.theoretical ?? 1e9))[0];
  const teamBest = Array.from({ length: n }, (_, k) => minOf(team.map(d => d.best[k])));
  const fieldBest = Array.from({ length: n }, (_, k) => minOf(all.map(d => d.best[k])));

  return {
    round,
    sectors: splits.sectors,
    drivers: ai ? [...team, ai] : team,
    team_best: teamBest,
    field_best: fieldBest,
    analysis: analyse(team, ai, teamBest),
  };
}

function analyse(team: DriverSplits[], ai: DriverSplits | undefined, teamBest: (number | null)[]): SplitAnalysis {
  const n = teamBest.length;
  const ideal = teamBest.every(x => x != null) ? rnd(sum(teamBest as number[]), 3) : null;
  const fastest = team.filter(d => d.best_lap != null).sort((a, b) => a.best_lap! - b.best_lap!)[0];

  const drivers: DriverSplitAnalysis[] = team.map(d => {
    const gaps = d.best.map((b, k) => (b != null && teamBest[k] != null ? rnd(b - teamBest[k]!, 3) : null));
    const deficit = d.theoretical != null && ideal != null ? rnd(d.theoretical - ideal, 3) : null;
    const share = gaps.map(g => (g != null && deficit ? rnd(g / deficit, 3) : null));
    const idx = gaps.map((g, k) => ({ g, k })).filter(x => x.g != null) as { g: number; k: number }[];
    const spread = d.avg.map((a, k) => (a != null && d.best[k] != null ? (a - d.best[k]!) / d.best[k]! : null));
    const wob = spread.map((s, k) => ({ s, k })).filter(x => x.s != null) as { s: number; k: number }[];
    return {
      driver: d.driver,
      deficit,
      gaps,
      share,
      weakest: idx.length && deficit ? idx.reduce((a, b) => (b.g > a.g ? b : a)).k : null,
      strongest: idx.length ? idx.reduce((a, b) => (b.g < a.g ? b : a)).k : null,
      on_table: d.best_lap != null && d.theoretical != null ? rnd(d.best_lap - d.theoretical, 3) : null,
      wobbliest: wob.length ? wob.reduce((a, b) => (b.s > a.s ? b : a)).k : null,
    };
  });

  // the sector where the team's best times differ most
  let decisive: SplitAnalysis['decisive'] = null;
  for (let k = 0; k < n; k++) {
    const v = team.map(d => d.best[k]).filter((x): x is number => x != null);
    if (v.length < 2) continue;
    const spread = rnd(Math.max(...v) - Math.min(...v), 3);
    if (!decisive || spread > decisive.spread) decisive = { sector: k, spread };
  }
  const aiGap = teamBest.map((t, k) => (t != null && ai?.best[k] != null ? rnd(t - ai.best[k]!, 3) : null));

  // ---- plain-language findings
  const I: SplitInsight[] = [];
  if (ideal != null && fastest?.best_lap != null) {
    I.push({ driver: null, text: `Putting the team's best sectors together gives a ${lapTime(ideal)}, ${s3(fastest.best_lap - ideal)} quicker than the fastest real lap (${first(fastest.driver)}, ${lapTime(fastest.best_lap)}).` });
  }
  if (decisive && decisive.spread > 0) {
    I.push({ driver: null, text: `Sector ${decisive.sector + 1} separates the team most: ${s3(decisive.spread)} between the quickest and slowest best times.` });
  }
  if (ai) {
    const worse = aiGap.map((g, k) => ({ g, k })).filter(x => x.g != null && x.g > 0) as { g: number; k: number }[];
    if (!worse.length) I.push({ driver: null, text: `The team's best beats the fastest AI (${first(ai.driver)}) in every sector.` });
    else {
      const w = worse.reduce((a, b) => (b.g > a.g ? b : a));
      I.push({ driver: null, text: `The fastest AI is quicker than the team's best in ${worse.length} of ${n} sectors, most in sector ${w.k + 1} (${s3(w.g)}).` });
    }
  }
  for (const a of drivers) {
    if (a.deficit == null) continue;
    if (a.deficit <= 0.0005) {
      I.push({ driver: a.driver, text: `${first(a.driver)} owns the team's ideal lap${a.on_table ? `, but left ${s3(a.on_table)} on the table versus that theoretical best` : ''}.` });
      continue;
    }
    const parts = [`${first(a.driver)} is ${s3(a.deficit)} off the team's ideal lap`];
    if (a.weakest != null) parts.push(`${Math.round((a.share[a.weakest] ?? 0) * 100)}% of it in sector ${a.weakest + 1} (${s3(a.gaps[a.weakest] ?? 0)})`);
    let text = parts.join(', ') + '.';
    if (a.strongest != null && a.strongest !== a.weakest) {
      const g = a.gaps[a.strongest] ?? 0;
      text += g <= 0.0005 ? ` Fastest in the team through sector ${a.strongest + 1}.` : ` Closest to the pace in sector ${a.strongest + 1} (${s3(g)}).`;
    }
    if (a.on_table != null && a.on_table > 0.05) text += ` ${s3(a.on_table)} left on the table versus their own theoretical best.`;
    I.push({ driver: a.driver, text });
  }

  return {
    ideal,
    fastest_lap: fastest?.best_lap != null ? { driver: fastest.driver, time: fastest.best_lap } : null,
    decisive,
    ai_gap: aiGap,
    drivers,
    insights: I,
  };
}
