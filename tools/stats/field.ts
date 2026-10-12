/* How strong was the field? iRacing gives AI cars no iRating, so a round's AI strength is
   estimated from the result: the rating at which the AI cars' finishes against the team's rated
   drivers would be par, using iRacing's own expected-score curve. An indication, not a fact. */
import type { FieldStrength } from '../lib/types.ts';
import { rnd } from '../lib/util.ts';
import type { Entry } from './race.ts';

const BR = 1600 / Math.LN2;   // iRacing's rating scale: 1600 points doubles the odds
const MAX = 12000;

/** Bradley-Terry strength behind an iRating: the odds of a beating b are strength(a) / strength(b). */
const strength = (r: number) => Math.exp(r / BR) - 1;

/** Strength of field as iRacing computes it. */
export function sof(ratings: number[]): number | null {
  if (!ratings.length) return null;
  let s = 0;
  for (const r of ratings) s += Math.exp(-r / BR);
  return BR * Math.log(ratings.length / s);
}

/** Places a driver rated r is expected to take from these opponents. */
function expected(r: number, others: number[]): number {
  const a = strength(r);
  let t = 0;
  for (const o of others) {
    const b = strength(o);
    t += a + b > 0 ? a / (a + b) : 0.5;
  }
  return t;
}

/** Root of an increasing function on 0..MAX, by bisection. */
function solve(f: (r: number) => number): number {
  let lo = 0, hi = MAX;
  for (let i = 0; i < 60; i++) {
    const m = (lo + hi) / 2;
    if (f(m) > 0) hi = m; else lo = m;
  }
  return (lo + hi) / 2;
}

function median(xs: (number | null)[]): number | null {
  const v = xs.filter((x): x is number => !!x).sort((a, b) => a - b);
  if (!v.length) return null;
  const n = v.length;
  return rnd(n % 2 ? v[(n - 1) / 2] : (v[n / 2 - 1] + v[n / 2]) / 2, 4);
}

/** One race's entries → the strength of the field the team raced against. */
export function fieldStrength(ents: Entry[]): FieldStrength {
  const rated = ents.filter(e => !e.ai && e.irating != null);
  const ai = ents.filter(e => e.ai);
  const hs = rated.map(e => e.irating!);
  const teamSof = sof(hs);
  const out: FieldStrength = {
    rated: rated.length,
    team_sof: teamSof === null ? null : Math.round(teamSof),
    ai_rating: null, ai_low: null, ai_high: null, sof_est: null,
    ai_wins: 0, pairs: ai.length * rated.length,
    ai_median_best: median(ai.map(e => e.best)),
    team_median_best: median(ents.filter(e => !e.ai).map(e => e.best)),
    groups: [],
  };
  if (!rated.length || !ai.length) return out;

  // every AI car against every rated team driver: who finished ahead?
  const tally = new Map<number, number>();
  for (const a of ai) {
    const beat = rated.filter(h => a.finish < h.finish).length;
    out.ai_wins += beat;
    tally.set(beat, (tally.get(beat) ?? 0) + 1);
  }
  // half a result is held back so a clean sweep either way still gives a finite number
  const target = Math.min(out.pairs - 0.5, Math.max(0.5, out.ai_wins));
  const n = ai.length;
  const field = solve(r => n * expected(r, hs) - target);
  out.ai_rating = Math.round(field);

  // AI cars grouped by how many team drivers they beat; one win and one loss against an
  // average AI car keeps a group that beat everyone (or no one) on the scale
  const all = [...hs];
  for (const [beat, cars] of [...tally].sort((a, b) => b[0] - a[0])) {
    const r = Math.round(solve(r => expected(r, hs) + 2 * expected(r, [field]) - (beat + 1)));
    out.groups.push({ beat, cars, rating: r });
    for (let k = 0; k < cars; k++) all.push(r);
  }
  const ratings = out.groups.map(g => g.rating);
  out.ai_low = Math.min(...ratings);
  out.ai_high = Math.max(...ratings);
  out.sof_est = Math.round(sof(all)!);
  return out;
}
