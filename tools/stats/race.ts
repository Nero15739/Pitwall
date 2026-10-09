/* One exported race (eventresult-*.json) → a compact race record. */
import { readFileSync } from 'node:fs';
import { basename } from 'node:path';
import { secs } from '../lib/util.ts';

export interface Entry {
  cust_id: number;
  name: string;
  ai: boolean;
  car: string;
  car_id: number;
  start: number;
  finish: number;
  laps: number;
  laps_led: number;
  best: number | null;
  best_lap_num: number;
  avg: number | null;
  inc: number;
  points: number;
  status: string;
  interval: number | null;
  qual: number | null;
  qual_rank: number | null;
  prac: number | null;
  helmet: string | null;
}

export interface Race {
  file: string;
  subsession: number;
  date: string;
  track: string;
  track_short: string;
  track_id: number;
  league: string | null;
  sof: number | null;
  race_laps: number | null;
  cautions: number;
  lead_changes: number;
  corners: number | null;
  temp_f: number | null;
  entries: Entry[];
}

/* eslint-disable @typescript-eslint/no-explicit-any */
export function processRace(path: string): Race {
  const raw = JSON.parse(readFileSync(path, 'utf8'));
  const ev = raw.data ?? raw;
  const sessions = new Map<number, any>(ev.session_results.map((s: any) => [s.simsession_number, s]));
  const race = sessions.get(0);
  if (!race) throw new Error('no race session (simsession 0)');

  const bests = (num: number) => {
    const s = sessions.get(num);
    return new Map<number, number | null>(s ? s.results.map((r: any) => [r.cust_id, secs(r.best_lap_time)]) : []);
  };
  const prac = bests(-2), qual = bests(-1);
  // qualifying rank across the whole field
  const qualRank = new Map<number, number>(
    [...qual].filter(([, t]) => t).sort((a, b) => (a[1] as number) - (b[1] as number)).map(([c], i) => [c, i + 1]),
  );

  const entries: Entry[] = race.results.map((r: any) => ({
    cust_id: r.cust_id,
    name: r.display_name,
    ai: Boolean(r.ai),
    car: r.car_name,
    car_id: r.car_id,
    start: r.starting_position + 1,
    finish: r.finish_position + 1,
    laps: r.laps_complete,
    laps_led: r.laps_lead,
    best: secs(r.best_lap_time),
    best_lap_num: r.best_lap_num,
    avg: secs(r.average_lap),
    inc: r.incidents,
    points: ('league_points' in r ? r.league_points : (r.champ_points ?? 0)) || 0,
    status: r.reason_out,
    interval: r.interval >= 0 ? secs(r.interval) : null,
    qual: qual.get(r.cust_id) ?? null,
    qual_rank: qualRank.get(r.cust_id) ?? null,
    prac: prac.get(r.cust_id) ?? null,
    helmet: r.helmet?.color1 ?? null,
  }));
  entries.sort((a, b) => a.finish - b.finish);

  const track = ev.track;
  const cfg = track.config_name;
  const w = ev.weather ?? {};
  return {
    file: basename(path),
    subsession: ev.subsession_id,
    date: ev.start_time,
    track: track.track_name + (cfg && cfg !== 'N/A' ? ` – ${cfg}` : ''),
    track_short: track.track_name,
    track_id: track.track_id,
    league: ev.league_season_name || ev.league_name || null,
    sof: ev.event_strength_of_field ?? null,
    race_laps: ev.event_laps_complete ?? null,
    cautions: ev.num_cautions ?? 0,
    lead_changes: ev.num_lead_changes ?? 0,
    corners: ev.corners_per_lap ?? null,
    temp_f: w.temp_units === 0 ? (w.temp_value ?? null) : null,
    entries,
  };
}
