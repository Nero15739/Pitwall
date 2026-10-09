/* Races of one season → every season stat the dashboard shows.
   Team stats cover human drivers only; AI times serve as a pace benchmark. */
import type {
  CarPace, CarSummary, HardestByDriver, HarvestFile, HotspotSummary, IncidentEvent, Result, Round, RoundSplits, Season,
  Standing, TrackDifficulty, TrackDriverRow, TrackPage,
} from '../lib/types.ts';
import { cmpTuple, maxBy, mean, minBy, mostCommon, pstdev, rnd, slug, sum } from '../lib/util.ts';
import { computeAwards } from './awards.ts';
import { hotspotSet, summarize } from './hotspots.ts';
import type { Entry, Race } from './race.ts';
import { roundSplits } from './splits.ts';

export interface SeasonInput {
  name: string;
  races: Race[];
  colorOf: (driver: string) => number;
  /** harvested replays per subsession: incident markers matched to drivers, plus any sector splits */
  incidents: Map<number, {
    events: Omit<IncidentEvent, 'round'>[]; length_m: number | null;
    splits?: HarvestFile['splits']; raw: HarvestFile['incidents'];
  }>;
  generated: string;
}

const minTime = (xs: (number | null)[]) => {
  const v = xs.filter((x): x is number => !!x);
  return v.length ? Math.min(...v) : null;
};

export function buildSeason({ name, races, colorOf, incidents, generated }: SeasonInput): Season {
  races = [...races].sort((a, b) => (a.date < b.date ? -1 : a.date > b.date ? 1 : 0));
  const teamNames = [...new Set(races.flatMap(r => r.entries.filter(e => !e.ai).map(e => e.name)))].sort();

  const rounds: Round[] = [];
  const results: Result[] = [];
  races.forEach((r, idx) => {
    const i = idx + 1;
    const ents = r.entries;
    const team = ents.filter(e => !e.ai);
    const fieldBest = minTime(ents.map(e => e.best));
    const teamBest = minTime(team.map(e => e.best));
    const aiBest = minTime(ents.filter(e => e.ai).map(e => e.best));
    const fl = minBy(ents.filter(e => e.best), e => e.best!);
    const tfl = minBy(team.filter(e => e.best), e => e.best!);
    const pole = minBy(ents, e => e.start)!;
    const teamSortedBest = team.filter(e => e.best).sort((a, b) => a.best! - b.best!);

    // fastest lap per car across the whole field (AI included) at this track
    const byCar = new Map<string, Entry[]>();
    for (const e of ents) if (e.best) byCar.set(e.car, [...(byCar.get(e.car) ?? []), e]);
    const carPace: CarPace[] = [...byCar].map(([car, es]) => {
      const b = minBy(es, e => e.best!)!;
      return {
        car, best: b.best!, by: b.name, ai: b.ai,
        entries: es.length, avg_best: rnd(mean(es.map(e => e.best))),
        gap_pct: fieldBest ? rnd((b.best! / fieldBest - 1) * 100) : null,
      };
    });
    carPace.sort((a, b) => a.best - b.best);

    const teamFinish = new Map([...team].sort((a, b) => a.finish - b.finish).map((e, k) => [e.cust_id, k + 1]));
    const teamQual = new Map(team.filter(e => e.qual).sort((a, b) => a.qual! - b.qual!).map((e, k) => [e.cust_id, k + 1]));

    const teamInc = sum(team.map(e => e.inc));
    const teamLaps = sum(team.map(e => e.laps));
    rounds.push({
      round: i,
      date: r.date,
      track: r.track,
      track_short: r.track_short,
      track_id: r.track_id,
      subsession: r.subsession,
      sof: r.sof,
      laps: r.race_laps,
      starters: ents.length,
      humans: team.length,
      ai: ents.length - team.length,
      winner: ents[0].name, winner_ai: ents[0].ai, winner_car: ents[0].car,
      win_margin: ents.length > 1 ? ents[1].interval : null,
      pole: pole.name, pole_ai: pole.ai,
      fastest: fl ? { name: fl.name, ai: fl.ai, time: fl.best!, car: fl.car } : null,
      team_fastest: tfl ? {
        name: tfl.name, time: tfl.best!, car: tfl.car,
        margin: teamSortedBest.length > 1 ? rnd(teamSortedBest[1].best! - tfl.best!) : null,
      } : null,
      field_best: fieldBest, team_best: teamBest, ai_best: aiBest,
      team_inc: teamInc,
      team_inc_per_lap: teamLaps ? rnd(teamInc / teamLaps, 4) : null,
      field_inc: sum(ents.map(e => e.inc)),
      car_pace: carPace,
      cars_used: [...new Set(team.map(e => e.car))].sort(),
      cautions: r.cautions,
      harvested: incidents.has(r.subsession),
    });

    for (const e of team) {
      const aiBeaten = ents.filter(o => o.ai && o.finish > e.finish).length;
      results.push({
        round: i, driver: e.name, car: e.car,
        start: e.start, finish: e.finish,
        team_finish: teamFinish.get(e.cust_id)!,
        team_qual: teamQual.get(e.cust_id) ?? null,
        gained: e.start - e.finish,
        laps: e.laps, laps_led: e.laps_led,
        best: e.best, best_lap_num: e.best_lap_num, avg: e.avg,
        qual: e.qual, qual_rank: e.qual_rank, prac: e.prac,
        inc: e.inc,
        inc_per_lap: e.laps ? rnd(e.inc / e.laps, 4) : null,
        points: e.points, status: e.status,
        gap_to_winner: e.interval,
        gap_field_pct: e.best && fieldBest ? rnd((e.best / fieldBest - 1) * 100) : null,
        gap_team_pct: e.best && teamBest ? rnd((e.best / teamBest - 1) * 100) : null,
        gap_team_s: e.best && teamBest ? rnd(e.best - teamBest) : null,
        consistency_pct: e.avg && e.best ? rnd((e.avg / e.best - 1) * 100) : null,
        ai_beaten: aiBeaten,
        finished: e.status === 'Running',
      });
    }
  });

  // ---- standings
  const per = new Map<string, Result[]>();
  for (const x of results) per.set(x.driver, [...(per.get(x.driver) ?? []), x]);
  const rows = [...per].map(([d, xs]) => {
    const laps = sum(xs.map(x => x.laps));
    const inc = sum(xs.map(x => x.inc));
    return {
      driver: d, color: colorOf(d),
      points: sum(xs.map(x => x.points)),
      races: xs.length,
      wins: xs.filter(x => x.finish === 1).length,
      podiums: xs.filter(x => x.finish <= 3).length,
      team_wins: xs.filter(x => x.team_finish === 1).length,
      poles: xs.filter(x => x.start === 1).length,
      team_fastest_laps: rounds.filter(r => r.team_fastest && r.team_fastest.name === d).length,
      best_finish: Math.min(...xs.map(x => x.finish)),
      avg_finish: rnd(mean(xs.map(x => x.finish)), 2),
      avg_team_finish: rnd(mean(xs.map(x => x.team_finish)), 2),
      avg_start: rnd(mean(xs.map(x => x.start)), 2),
      avg_gained: rnd(mean(xs.map(x => x.gained)), 2),
      laps, laps_led: sum(xs.map(x => x.laps_led)),
      inc, inc_per_race: rnd(inc / xs.length, 2),
      inc_per_lap: laps ? rnd(inc / laps, 4) : null,
      avg_gap_team_pct: rnd(mean(xs.map(x => x.gap_team_pct))),
      avg_gap_field_pct: rnd(mean(xs.map(x => x.gap_field_pct))),
      avg_consistency_pct: rnd(mean(xs.map(x => x.consistency_pct))),
      ai_beaten: sum(xs.map(x => x.ai_beaten)),
      dnfs: xs.filter(x => !x.finished).length,
      cars: mostCommon(xs.map(x => x.car)),
      finish_std: xs.length > 1 ? rnd(pstdev(xs.map(x => x.finish)), 2) : null,
    };
  });
  rows.sort((a, b) => cmpTuple([-a.points, -a.wins, a.avg_finish ?? 0], [-b.points, -b.wins, b.avg_finish ?? 0]));
  const leaderPts = rows[0]?.points ?? 0;
  const standings: Standing[] = rows.map((s, k) => ({ ...s, pos: k + 1, gap_to_leader: leaderPts - s.points }));

  // ---- points progression (cumulative)
  const progression: Record<string, number[]> = {};
  for (const d of teamNames) {
    let tot = 0;
    progression[d] = rounds.map(rd => {
      const x = (per.get(d) ?? []).find(x => x.round === rd.round);
      tot += x ? x.points : 0;
      return tot;
    });
  }

  // ---- head to head (race finishes and qualifying)
  const grid = () => Object.fromEntries(teamNames.map(a => [a, Object.fromEntries(teamNames.map(b => [b, 0]))]));
  const h2hRace: Record<string, Record<string, number>> = grid();
  const h2hQual: Record<string, Record<string, number>> = grid();
  for (const rd of rounds) {
    const xs = results.filter(x => x.round === rd.round);
    for (const a of xs) for (const b of xs) {
      if (a === b) continue;
      if (a.finish < b.finish) h2hRace[a.driver][b.driver]++;
      if (a.qual && (!b.qual || a.qual < b.qual)) h2hQual[a.driver][b.driver]++;
    }
  }

  // ---- tracks: difficulty for the group
  const tracks: TrackDifficulty[] = rounds.map(rd => {
    const xs = results.filter(x => x.round === rd.round);
    const gaps = xs.map(x => x.gap_team_pct).filter((g): g is number => g != null);
    return {
      round: rd.round, track: rd.track, track_short: rd.track_short,
      inc_per_lap: rd.team_inc_per_lap, inc: rd.team_inc,
      avg_inc: xs.length ? rnd(rd.team_inc / xs.length, 2) : null,
      pace_spread_pct: gaps.length > 1 ? rnd(pstdev(gaps), 3) : null,
      avg_gained: rnd(mean(xs.map(x => x.gained)), 2),
      avg_consistency_pct: rnd(mean(xs.map(x => x.consistency_pct))),
      team_vs_ai_pct: rd.team_best && rd.ai_best ? rnd((rd.team_best / rd.ai_best - 1) * 100) : null,
      difficulty: 0, difficulty_rank: 0,
    };
  });
  // difficulty: incidents per lap (50%) + consistency loss (30%) + positions lost (20%), as z-scores
  const zscores = (vals: (number | null)[]) => {
    const v = vals.filter((x): x is number => x != null);
    if (v.length < 2) return vals.map(() => 0);
    const m = sum(v) / v.length, sd = pstdev(v) || 1;
    return vals.map(x => (x != null ? (x - m) / sd : 0));
  };
  const zi = zscores(tracks.map(t => t.inc_per_lap));
  const zc = zscores(tracks.map(t => t.avg_consistency_pct));
  const zg = zscores(tracks.map(t => -(t.avg_gained || 0)));
  tracks.forEach((t, k) => { t.difficulty = rnd(zi[k] * 0.5 + zc[k] * 0.3 + zg[k] * 0.2, 3); });
  [...tracks].sort((a, b) => b.difficulty - a.difficulty).forEach((t, k) => { t.difficulty_rank = k + 1; });

  const hardestByDriver: Record<string, HardestByDriver> = {};
  for (const d of teamNames) {
    const xs = per.get(d);
    if (!xs?.length) continue;
    const worstInc = maxBy(xs, x => x.inc_per_lap || 0)!;
    const worstPace = maxBy(xs, x => (x.gap_team_pct != null ? x.gap_team_pct : -1))!;
    const best = xs.reduce((b, x) => (cmpTuple([x.team_finish, x.inc_per_lap || 0], [b.team_finish, b.inc_per_lap || 0]) < 0 ? x : b));
    hardestByDriver[d] = {
      most_incidents: { round: worstInc.round, inc: worstInc.inc, inc_per_lap: worstInc.inc_per_lap },
      slowest_vs_team: { round: worstPace.round, gap_pct: worstPace.gap_team_pct },
      best_track: { round: best.round, team_finish: best.team_finish, finish: best.finish },
    };
  }

  // ---- cars
  const carRows = new Map<string, Result[]>();
  for (const x of results) carRows.set(x.car, [...(carRows.get(x.car) ?? []), x]);
  const cars: CarSummary[] = [...carRows].map(([car, xs]) => ({
    car, starts: xs.length, drivers: [...new Set(xs.map(x => x.driver))].sort(),
    wins: xs.filter(x => x.finish === 1).length,
    avg_finish: rnd(mean(xs.map(x => x.finish)), 2),
    avg_gap_team_pct: rnd(mean(xs.map(x => x.gap_team_pct))),
    avg_inc_per_lap: rnd(mean(xs.map(x => x.inc_per_lap)), 4),
    fastest_at: rounds.filter(rd => rd.car_pace.length && rd.car_pace[0].car === car).map(rd => rd.round),
    rounds_in_field: sum(rounds.map(rd => rd.car_pace.filter(cp => cp.car === car).length)),
  }));
  cars.sort((a, b) => cmpTuple([-a.starts, a.avg_finish || 99], [-b.starts, b.avg_finish || 99]));

  const awards = computeAwards(results, rounds, standings, per);
  const trackPages = buildTrackPages(races, rounds, results, standings, tracks, incidents);

  return {
    season: name,
    slug: slug(name),
    league: races.find(r => r.league)?.league ?? name,
    generated,
    drivers: teamNames.map(d => ({ name: d, color: colorOf(d) })),
    rounds, results, standings, progression,
    h2h_race: h2hRace, h2h_qual: h2hQual,
    tracks, hardest_by_driver: hardestByDriver, cars, awards,
    track_pages: trackPages,
  };
}

// ---------------------------------------------------------------- per-track pages
function buildTrackPages(
  races: Race[], rounds: Round[], results: Result[], standings: Standing[], tracks: TrackDifficulty[],
  incidents: SeasonInput['incidents'],
): TrackPage[] {
  const raceOf = new Map(rounds.map((rd, k) => [rd.round, races[k]]));
  const seasonRate = new Map(standings.map(s => [s.driver, s.inc_per_lap]));
  const totLaps = sum(results.map(x => x.laps)), totInc = sum(results.map(x => x.inc));
  const ids = [...new Set(rounds.map(r => r.track_id))];

  return ids.map(id => {
    const rs = rounds.filter(r => r.track_id === id);
    const nums = rs.map(r => r.round);
    const xs = results.filter(x => nums.includes(x.round));
    const allEntries = rs.flatMap(r => raceOf.get(r.round)!.entries);
    const laps = sum(xs.map(x => x.laps));
    const teamInc = sum(xs.map(x => x.inc));

    // incident markers from harvested replays, tagged with their round
    const events: IncidentEvent[] = [];
    const byRound: Record<string, HotspotSummary> = {};
    const splits: RoundSplits[] = [];
    let lengthM: number | null = null;
    for (const r of rs) {
      const h = incidents.get(r.subsession);
      if (!h) continue;
      lengthM ??= h.length_m;
      const evs = h.events.map(e => ({ ...e, round: r.round }));
      events.push(...evs);
      byRound[String(r.round)] = summarize(evs, h.length_m);
      const sp = h.splits ? roundSplits(r.round, raceOf.get(r.round)!, h.splits, h.raw) : null;
      if (sp) splits.push(sp);
    }
    const harvested = rs.filter(r => incidents.has(r.subsession));

    const drivers: TrackDriverRow[] = xs.map(x => {
      const sr = seasonRate.get(x.driver);
      const isHarvested = incidents.has(rs.find(r => r.round === x.round)!.subsession);
      return {
        driver: x.driver, round: x.round, car: x.car,
        start: x.start, finish: x.finish, laps: x.laps,
        inc: x.inc,
        inc_per_10: x.inc_per_lap != null ? rnd(x.inc_per_lap * 10, 2) : null,
        season_inc_per_10: sr != null ? rnd(sr * 10, 2) : null,
        markers: isHarvested ? events.filter(e => e.round === x.round && e.driver === x.driver).length : null,
        points: x.points, status: x.status,
      };
    }).sort((a, b) => a.round - b.round || a.finish - b.finish);

    // cleanest / roughest across this track's rounds
    const agg = new Map<string, { inc: number; laps: number }>();
    for (const x of xs) {
      const a = agg.get(x.driver) ?? { inc: 0, laps: 0 };
      a.inc += x.inc; a.laps += x.laps;
      agg.set(x.driver, a);
    }
    const rates = [...agg].filter(([, a]) => a.laps > 0).map(([driver, a]) => ({ driver, inc_per_10: rnd((a.inc / a.laps) * 10, 2) }));
    const ranks = tracks.filter(t => nums.includes(t.round)).map(t => t.difficulty_rank);

    return {
      track_id: id,
      track: rs[0].track,
      track_short: rs[0].track_short,
      rounds: nums,
      laps,
      team_inc: teamInc,
      field_inc: sum(allEntries.map(e => e.inc)),
      ai_inc: sum(allEntries.filter(e => e.ai).map(e => e.inc)),
      team_inc_per_10: laps ? rnd((teamInc / laps) * 10, 2) : null,
      season_team_inc_per_10: totLaps ? rnd((totInc / totLaps) * 10, 2) : null,
      difficulty_rank: ranks.length ? Math.min(...ranks) : null,
      cleanest: minBy(rates, r => r.inc_per_10) ?? null,
      roughest: maxBy(rates, r => r.inc_per_10) ?? null,
      drivers,
      harvested_rounds: harvested.map(r => r.round),
      pending: rs.filter(r => !incidents.has(r.subsession)).map(r => ({ round: r.round, subsession: r.subsession })),
      hotspots: harvested.length ? hotspotSet(events, lengthM) : null,
      by_round: byRound,
      splits,
    };
  });
}
