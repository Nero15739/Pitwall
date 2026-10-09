/* Season awards: the numbers behind the bragging rights. */
import type { Award, Result, Round, Standing } from '../../src/lib/shared/types.ts';
import { cmpTuple, maxBy, mean, minBy, mostCommon } from '../lib/util.ts';

const f = (x: number, d: number) => x.toFixed(d);
const signed = (x: number, d: number) => (x >= 0 ? '+' : '') + x.toFixed(d);

export function computeAwards(results: Result[], rounds: Round[], standings: Standing[], per: Map<string, Result[]>): Award[] {
  if (!results.length) return [];
  const track = new Map(rounds.map(r => [r.round, r.track_short]));
  const A: Award[] = [];
  const add = (key: string, title: string, icon: string, driver: string, value: string, detail: string) =>
    A.push({ key, title, icon, driver, value, detail });

  let x = maxBy(results, x => x.gained)!;
  if (x.gained > 0)
    add('charger', 'Hard Charger', 'rocket', x.driver, `+${x.gained}`,
      `places gained in one race: P${x.start} → P${x.finish} at ${track.get(x.round)}`);

  x = maxBy(results, x => x.inc)!;
  add('wrecking', 'Wrecking Ball', 'bolt', x.driver, `${x.inc}x`, `incidents in a single race at ${track.get(x.round)}`);

  const eligible = standings.filter(s => s.inc_per_lap != null && s.races >= Math.max(1, Math.floor(rounds.length / 2)));
  if (eligible.length) {
    const s = minBy(eligible, s => s.inc_per_lap!)!;
    add('clean', 'Clean Machine', 'shield', s.driver, f(s.inc_per_lap! * 10, 1), 'incidents per 10 laps, the lowest rate on the team');
  }

  const cons = standings.filter(s => s.avg_consistency_pct != null);
  if (cons.length) {
    const s = minBy(cons, s => s.avg_consistency_pct!)!;
    add('metronome', 'Metronome', 'metronome', s.driver, `${f(s.avg_consistency_pct!, 2)}%`,
      'average lap within this much of their best lap, the most consistent pace');
  }

  {
    const key = (s: Standing) => [s.team_fastest_laps, -(s.avg_gap_team_pct || 99)];
    const s = standings.reduce((b, s) => (cmpTuple(key(s), key(b)) > 0 ? s : b));
    if (s.team_fastest_laps)
      add('speed', 'Speed Demon', 'stopwatch', s.driver, `${s.team_fastest_laps}`, `team fastest laps from ${rounds.length} rounds`);
  }

  const qualAvg = (d: string) => mean((per.get(d) ?? []).filter(x => x.team_qual).map(x => x.team_qual));
  const qa = minBy(standings, s => qualAvg(s.driver) || 99)!;
  add('quali', 'Quali Ace', 'flag', qa.driver, f(qualAvg(qa.driver)!, 1), 'average qualifying position within the team');

  let s = maxBy(standings, s => s.avg_gained || -99)!;
  add('racecraft', 'Sunday Specialist', 'trend', s.driver, signed(s.avg_gained!, 1), 'average places gained from grid to flag');

  s = maxBy(standings, s => s.laps_led)!;
  if (s.laps_led) add('leader', 'Front Runner', 'crown', s.driver, `${s.laps_led}`, 'laps led this season');

  s = maxBy(standings, s => s.ai_beaten)!;
  add('ai', 'AI Slayer', 'robot', s.driver, `${s.ai_beaten}`, 'AI cars finished ahead of, all season');

  s = maxBy(standings, s => Object.keys(s.cars).length)!;
  if (Object.keys(s.cars).length > 1)
    add('hopper', 'Garage Tourist', 'car', s.driver, `${Object.keys(s.cars).length}`,
      'different cars driven: ' + Object.keys(s.cars).join(', '));

  const loyal = standings.filter(s => Object.keys(s.cars).length === 1 && s.races > 1);
  if (loyal.length) {
    const l = maxBy(loyal, s => s.races)!;
    add('loyal', 'Brand Loyal', 'heart', l.driver, `${l.races}/${l.races}`, `races in the ${Object.keys(l.cars)[0]}`);
  }

  const stds = standings.filter(s => s.finish_std != null);
  if (stds.length) {
    const st = minBy(stds, s => s.finish_std!)!;
    add('steady', 'Mr Reliable', 'target', st.driver, `±${f(st.finish_std!, 1)}`,
      'places of variation in finishing position, the steadiest results');
  }

  // closest team battle at the flag
  let best: { gap: number; a: Result; b: Result; rd: Round } | null = null;
  for (const rd of rounds) {
    const xs = results.filter(x => x.round === rd.round && x.gap_to_winner != null).sort((a, b) => a.finish - b.finish);
    for (let k = 0; k + 1 < xs.length; k++) {
      const gap = xs[k + 1].gap_to_winner! - xs[k].gap_to_winner!;
      if (best === null || gap < best.gap) best = { gap, a: xs[k], b: xs[k + 1], rd };
    }
  }
  if (best)
    add('photo', 'Photo Finish', 'camera', `${best.a.driver} vs ${best.b.driver}`, `${f(best.gap, 3)}s`,
      `between team-mates at the line, ${best.rd.track_short}`);

  // biggest winning margin over the next car
  const margins = rounds.filter(rd => rd.win_margin && !rd.winner_ai);
  if (margins.length) {
    const rd = maxBy(margins, rd => rd.win_margin!)!;
    add('dominant', 'Dominant Win', 'trophy', rd.winner, `${f(rd.win_margin!, 1)}s`, `winning margin at ${rd.track_short}`);
  }

  // fast but unrewarded: team fastest lap without the team win
  for (const rd of rounds) {
    const tf = rd.team_fastest;
    if (!tf) continue;
    const r = results.find(x => x.round === rd.round && x.driver === tf.name)!;
    if (r.team_finish > 1) {
      add('unlucky', 'Fastest, Not First', 'clock', r.driver, `P${r.finish}`,
        `set the team's fastest lap at ${rd.track_short} but finished P${r.finish}`);
      break;
    }
  }

  // practice hero: most rounds topping team practice
  const pracTop: string[] = [];
  for (const rd of rounds) {
    const xs = results.filter(x => x.round === rd.round && x.prac);
    if (xs.length < 2) continue;
    pracTop.push(minBy(xs, x => x.prac!)!.driver);
  }
  if (pracTop.length) {
    const [who, n] = Object.entries(mostCommon(pracTop))[0];
    add('practice', 'Practice Hero', 'wrench', who, `${n}`, 'rounds topping the team practice times');
  }

  s = maxBy(standings, s => s.inc_per_race ?? 0)!;
  add('insurance', 'Insurance Premium', 'shield-alert', s.driver, f(s.inc_per_race!, 1),
    'average incidents per race, the highest on the team');
  return A;
}
