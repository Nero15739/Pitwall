/* The compiled data contract. tools/compile.ts writes these shapes to public/data/*.json
   and the web app reads them. Field names stay snake_case to match the JSON on disk. */

// ---------------------------------------------------------------- index
export interface SeasonIndexEntry {
  name: string;
  slug: string;
  league: string;
  rounds: number;
  first: string;
  last: string;
  leader: string | null;
  file: string;
}

export interface SeasonIndex {
  generated: string;
  seasons: SeasonIndexEntry[];
  errors: string[];
}

// ---------------------------------------------------------------- season
export interface Driver { name: string; color: number }

export interface CarPace {
  car: string; best: number; by: string; ai: boolean;
  entries: number; avg_best: number | null; gap_pct: number | null;
}

export interface Round {
  round: number;
  date: string;
  track: string;
  track_short: string;
  track_id: number;
  subsession: number;
  sof: number | null;
  laps: number | null;
  starters: number;
  humans: number;
  ai: number;
  winner: string; winner_ai: boolean; winner_car: string;
  win_margin: number | null;
  pole: string; pole_ai: boolean;
  fastest: { name: string; ai: boolean; time: number; car: string } | null;
  team_fastest: { name: string; time: number; car: string; margin: number | null } | null;
  field_best: number | null; team_best: number | null; ai_best: number | null;
  team_inc: number;
  team_inc_per_lap: number | null;
  field_inc: number;
  car_pace: CarPace[];
  cars_used: string[];
  cautions: number;
  harvested: boolean;
}

export interface Result {
  round: number; driver: string; car: string;
  start: number; finish: number; team_finish: number; team_qual: number | null;
  gained: number; laps: number; laps_led: number;
  best: number | null; best_lap_num: number; avg: number | null;
  qual: number | null; qual_rank: number | null; prac: number | null;
  inc: number; inc_per_lap: number | null;
  points: number; status: string;
  gap_to_winner: number | null;
  gap_field_pct: number | null; gap_team_pct: number | null; gap_team_s: number | null;
  consistency_pct: number | null;
  ai_beaten: number;
  finished: boolean;
}

export interface Standing {
  driver: string; color: number; pos: number;
  points: number; races: number; wins: number; podiums: number; team_wins: number; poles: number;
  team_fastest_laps: number; best_finish: number;
  avg_finish: number | null; avg_team_finish: number | null; avg_start: number | null; avg_gained: number | null;
  laps: number; laps_led: number; inc: number; inc_per_race: number | null; inc_per_lap: number | null;
  avg_gap_team_pct: number | null; avg_gap_field_pct: number | null; avg_consistency_pct: number | null;
  ai_beaten: number; dnfs: number; cars: Record<string, number>; finish_std: number | null;
  gap_to_leader: number;
}

export interface TrackDifficulty {
  round: number; track: string; track_short: string;
  inc_per_lap: number | null; inc: number; avg_inc: number | null;
  pace_spread_pct: number | null; avg_gained: number | null;
  avg_consistency_pct: number | null; team_vs_ai_pct: number | null;
  difficulty: number; difficulty_rank: number;
}

export interface HardestByDriver {
  most_incidents: { round: number; inc: number; inc_per_lap: number | null };
  slowest_vs_team: { round: number; gap_pct: number | null };
  best_track: { round: number; team_finish: number; finish: number };
}

export interface CarSummary {
  car: string; starts: number; drivers: string[]; wins: number;
  avg_finish: number | null; avg_gap_team_pct: number | null; avg_inc_per_lap: number | null;
  fastest_at: number[]; rounds_in_field: number;
}

export interface Award {
  key: string; title: string; icon: string; driver: string; value: string; detail: string;
}

// ---------------------------------------------------------------- hotspots
export type IncidentKind = 'off' | 'spin';

/** One incident spotted in a replay, placed on the lap. */
export interface IncidentEvent {
  pct: number;            // 0..1 lap distance from the start/finish line
  driver: string;
  team: boolean;          // a human team driver (otherwise AI or a non-team human)
  ai: boolean;
  lap: number;
  t: number;              // session time, seconds
  kind: IncidentKind;     // left the circuit, or spun (rolled backwards)
  off: boolean;           // went off the circuit (a spin may also leave it)
  round: number;
  season?: string;        // season slug, set in all-season sets
}

export interface Hotspot {
  pct: number;            // centre of the cluster
  from: number; to: number;
  count: number;
  distance_m: number | null;
  drivers: { name: string; n: number; team: boolean; ai: boolean }[];
}

export interface HotspotSummary {
  n_team: number; n_field: number;
  density_team: number[]; // smoothed events per bin (HOTSPOT_BINS bins around the lap)
  density_field: number[];
  top_team: Hotspot[];
  top_field: Hotspot[];
}

export interface HotspotSet extends HotspotSummary {
  events: IncidentEvent[];
  length_m: number | null;
}

export const HOTSPOT_BINS = 200;

// ---------------------------------------------------------------- per-track page
export interface TrackDriverRow {
  driver: string; round: number; car: string;
  start: number; finish: number; laps: number;
  inc: number; inc_per_10: number | null; season_inc_per_10: number | null;
  markers: number | null; // off-tracks and spins found in the replay (null when the round isn't harvested)
  points: number; status: string;
}

// ---------------------------------------------------------------- sector splits (from replays)
export interface DriverSplits {
  driver: string;
  team: boolean;
  ai: boolean;
  best: (number | null)[];      // best time in each sector, seconds
  avg: (number | null)[];       // average over clean laps
  best_lap: number | null;      // best complete timed lap
  theoretical: number | null;   // sum of best sectors
  clean_laps: number;
}

export interface DriverSplitAnalysis {
  driver: string;
  deficit: number | null;         // theoretical best minus the team's ideal lap
  gaps: (number | null)[];        // per sector: best minus the team's best
  share: (number | null)[];       // per sector: fraction of the deficit lost there
  weakest: number | null;         // sector index losing the most time
  strongest: number | null;       // sector index closest to (or setting) the team best
  on_table: number | null;        // best lap minus theoretical best
  wobbliest: number | null;       // sector with the largest average-vs-best spread
}

export interface SplitInsight { driver: string | null; text: string }

export interface SplitAnalysis {
  ideal: number | null;           // sum of the team's best sectors
  fastest_lap: { driver: string; time: number } | null;
  decisive: { sector: number; spread: number } | null;  // where the team's best times differ most
  ai_gap: (number | null)[];      // team best minus fastest-AI best (positive = AI quicker)
  drivers: DriverSplitAnalysis[];
  insights: SplitInsight[];
}

export interface RoundSplits {
  round: number;
  sectors: number[];            // start of each sector as lap distance (first is 0)
  drivers: DriverSplits[];      // team drivers by theoretical best, then the fastest AI
  team_best: (number | null)[];
  field_best: (number | null)[];
  analysis: SplitAnalysis;
}

export interface TrackPage {
  track_id: number;
  track: string;
  track_short: string;
  rounds: number[];
  laps: number;
  team_inc: number;
  field_inc: number;
  ai_inc: number;
  team_inc_per_10: number | null;
  season_team_inc_per_10: number | null;
  difficulty_rank: number | null;
  cleanest: { driver: string; inc_per_10: number } | null;
  roughest: { driver: string; inc_per_10: number } | null;
  drivers: TrackDriverRow[];
  harvested_rounds: number[];
  pending: { round: number; subsession: number }[];
  hotspots: HotspotSet | null;                          // whole season at this track
  by_round: Record<string, HotspotSummary>;             // keyed by round number
  splits: RoundSplits[];                                // rounds whose replay had a splits pass
}

export interface Season {
  season: string;
  slug: string;
  league: string;
  generated: string;
  drivers: Driver[];
  rounds: Round[];
  results: Result[];
  standings: Standing[];
  progression: Record<string, number[]>;
  h2h_race: Record<string, Record<string, number>>;
  h2h_qual: Record<string, Record<string, number>>;
  tracks: TrackDifficulty[];
  hardest_by_driver: Record<string, HardestByDriver>;
  cars: CarSummary[];
  awards: Award[];
  track_pages: TrackPage[];
}

// ---------------------------------------------------------------- track geometry
export interface TrackMap {
  viewBox: string;
  path: string;           // racing line, one closed loop
  sf: string | null;      // start/finish marker
  offset: number;         // lap pct 0 sits at offset * pathLength
  direction: 1 | -1;      // +1 when the lap runs along the path direction
}

export interface TrackInfo {
  id: number;
  name: string;
  short: string;
  map: TrackMap | null;
  length_m: number | null;
  seasons: { slug: string; name: string; rounds: number[] }[];
  hotspots_all: HotspotSet | null;
}

export interface TracksFile {
  generated: string;
  source: string;
  tracks: Record<string, TrackInfo>;
}

// ---------------------------------------------------------------- harvested replay file (data/incidents)
export interface HarvestFile {
  version: 1;
  subsession: number;
  track: { id: number; name: string; config: string; length_m: number | null };
  harvested_at: string;
  method: 'telemetry';
  session: { num: number; type: string };
  drivers: { car_idx: number; cust_id: number; name: string; ai: boolean; number: string }[];
  /** incidents spotted in telemetry: leaving the circuit ('off') or rolling backwards ('spin') */
  incidents: {
    frame: number; t: number; lap: number; pct: number;
    car_idx: number; cust_id: number; name: string; ai: boolean; off_track: boolean;
    kind: IncidentKind;
  }[];
  /** sector crossing times from a fast-forward pass over the race (absent if skipped) */
  splits?: {
    sectors: number[];          // SplitTimeInfo sector starts (lap distance), first is 0
    laps: {
      car_idx: number; cust_id: number; lap: number;
      start: number;            // session time the lap began (crossing the line)
      sectors: number[];        // time in each sector, seconds
      lap_time: number;
      pit: boolean;             // touched pit road during the lap
    }[];
  };
}
