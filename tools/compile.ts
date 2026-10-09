/* Pit Wall compiler: race exports + harvested replay incidents + track maps → public/data/*.json.
   The browser only renders; every stat is computed here, once.

     node tools/compile.ts            compile (only new or changed races are reprocessed)
     node tools/compile.ts --force    ignore the per-race cache                          */
import { createHash } from 'node:crypto';
import { existsSync, readdirSync, readFileSync, unlinkSync } from 'node:fs';
import { basename, join, relative } from 'node:path';
import type { HarvestFile, IncidentEvent, Season, SeasonIndex, TrackInfo, TracksFile } from './lib/types.ts';
import { type Config, loadConfig } from './lib/config.ts';
import { atomicWrite, fileSig } from './lib/util.ts';
import { hotspotSet } from './stats/hotspots.ts';
import { processRace, type Race } from './stats/race.ts';
import { buildSeason } from './stats/season.ts';
import { loadTrackMaps } from './trackmaps.ts';

const BUILD_VERSION = 1; // bump to invalidate every cached race
const RESERVED = new Set(['incidents']);

/** Every folder under dataDir holding eventresult-*.json files is a season. */
export function findSeasons(cfg: Config): Map<string, string[]> {
  const seasons = new Map<string, string[]>();
  if (!existsSync(cfg.dataDir)) return seasons;
  for (const d of readdirSync(cfg.dataDir, { withFileTypes: true }).sort((a, b) => (a.name < b.name ? -1 : 1))) {
    if (!d.isDirectory() || d.name.startsWith('.') || RESERVED.has(d.name)) continue;
    const dir = join(cfg.dataDir, d.name);
    const files = (readdirSync(dir, { recursive: true }) as string[])
      .filter(f => /^eventresult-.*\.json$/.test(basename(f)))
      .map(f => join(dir, f))
      .sort();
    if (files.length) seasons.set(d.name, files);
  }
  return seasons;
}

export function incidentFiles(cfg: Config): string[] {
  if (!existsSync(cfg.incidentsDir)) return [];
  return readdirSync(cfg.incidentsDir).filter(f => /^incidents-\d+\.json$/.test(f)).map(f => join(cfg.incidentsDir, f));
}

function loadRaceCached(cfg: Config, path: string, force: boolean): { race: Race; built: boolean } {
  const key = createHash('sha1').update(relative(cfg.dataDir, path)).digest('hex').slice(0, 16);
  const cpath = join(cfg.cacheDir, 'races', `${key}.json`);
  const sig = `v${BUILD_VERSION}-${fileSig(path)}`;
  if (!force && existsSync(cpath)) {
    try {
      const cached = JSON.parse(readFileSync(cpath, 'utf8'));
      if (cached._sig === sig) return { race: cached.race, built: false };
    } catch { /* rebuild */ }
  }
  const race = processRace(path);
  atomicWrite(cpath, { _sig: sig, race });
  return { race, built: true };
}

/** Replay incidents for one race, matched to that race's drivers by customer ID (names can differ). */
function matchIncidents(race: Race, h: HarvestFile): Omit<IncidentEvent, 'round'>[] {
  const byId = new Map(race.entries.map(e => [e.cust_id, e]));
  const byName = new Map(race.entries.map(e => [e.name, e]));
  return h.incidents.map(i => {
    const e = byId.get(i.cust_id) ?? byName.get(i.name);
    return {
      pct: i.pct, driver: e?.name ?? i.name, team: e ? !e.ai : false, ai: e ? e.ai : i.ai,
      lap: i.lap, t: i.t, kind: i.kind ?? (i.off_track ? 'off' : 'spin'), off: i.off_track,
    };
  });
}

export interface CompileResult { seasons: number; races: number; changed: number; errors: string[]; ms: number }

export async function compile({ force = false, log = console.log } = {}): Promise<CompileResult> {
  const t0 = performance.now();
  const cfg = loadConfig();
  const generated = new Date().toISOString().replace(/\.\d+Z$/, 'Z');
  const errors: string[] = [];
  let changed = 0;

  // ---- races
  const loaded = new Map<string, Race[]>();
  const humans = new Set<string>();
  for (const [name, files] of findSeasons(cfg)) {
    const races: Race[] = [];
    for (const f of files) {
      try {
        const { race, built } = loadRaceCached(cfg, f, force);
        changed += Number(built);
        races.push(race);
        race.entries.filter(e => !e.ai).forEach(e => humans.add(e.name));
      } catch (e) {
        errors.push(`${relative(cfg.dataDir, f)}: ${(e as Error).message}`);
      }
    }
    loaded.set(name, races);
  }

  // ---- harvested replay incidents, by subsession
  const harvests = new Map<number, HarvestFile>();
  for (const f of incidentFiles(cfg)) {
    try {
      const h = JSON.parse(readFileSync(f, 'utf8')) as HarvestFile;
      harvests.set(h.subsession, h);
    } catch (e) {
      errors.push(`${relative(cfg.dataDir, f)}: ${(e as Error).message}`);
    }
  }

  // colour slots are assigned once across every season so a driver keeps their colour
  const palette = [...humans].sort();
  const colorOf = (d: string) => Math.max(0, palette.indexOf(d)) % 8;

  // ---- seasons
  const index: SeasonIndex['seasons'] = [];
  const built: Season[] = [];
  for (const [name, races] of loaded) {
    if (!races.length) continue;
    const incidents: Parameters<typeof buildSeason>[0]['incidents'] = new Map();
    for (const r of races) {
      const h = harvests.get(r.subsession);
      if (h) incidents.set(r.subsession, { events: matchIncidents(r, h), length_m: h.track.length_m, splits: h.splits, raw: h.incidents });
    }
    const season = buildSeason({ name, races, colorOf, incidents, generated });
    built.push(season);
    const file = `season-${season.slug}.json`;
    atomicWrite(join(cfg.dataOut, file), season);
    const R = season.rounds;
    index.push({
      name, slug: season.slug, league: season.league, rounds: R.length,
      first: R[0].date, last: R[R.length - 1].date,
      leader: season.standings[0]?.driver ?? null, file,
    });
  }
  index.sort((a, b) => (a.last < b.last ? 1 : a.last > b.last ? -1 : 0));

  // ---- tracks: geometry + every season's hotspots at each track
  const ids = [...new Set(built.flatMap(s => s.rounds.map(r => r.track_id)))];
  const maps = await loadTrackMaps(cfg, ids, log);
  const tracks: Record<string, TrackInfo> = {};
  for (const id of ids) {
    const pages = built.flatMap(s => s.track_pages.filter(p => p.track_id === id).map(p => ({ s, p })));
    const events = pages.flatMap(({ s, p }) => (p.hotspots?.events ?? []).map(e => ({ ...e, season: s.slug })));
    const lengthM = pages.find(({ p }) => p.hotspots?.length_m)?.p.hotspots?.length_m ?? null;
    tracks[String(id)] = {
      id,
      name: pages[0].p.track,
      short: pages[0].p.track_short,
      map: maps.get(id) ?? null,
      length_m: lengthM,
      seasons: pages.map(({ s, p }) => ({ slug: s.slug, name: s.season, rounds: p.rounds })),
      hotspots_all: events.length ? hotspotSet(events, lengthM) : null,
    };
  }
  const tracksFile: TracksFile = { generated, source: cfg.trackMapSource, tracks };
  atomicWrite(join(cfg.dataOut, 'tracks.json'), tracksFile);

  // remove data files for seasons that no longer exist
  const keep = new Set(index.map(s => s.file));
  if (existsSync(cfg.dataOut)) {
    for (const f of readdirSync(cfg.dataOut)) {
      if (/^season-.*\.json$/.test(f) && !keep.has(f)) unlinkSync(join(cfg.dataOut, f));
    }
  }
  atomicWrite(join(cfg.dataOut, 'seasons.json'), { generated, seasons: index, errors } satisfies SeasonIndex);

  return {
    seasons: index.length,
    races: [...loaded.values()].reduce((a, r) => a + r.length, 0),
    changed, errors, ms: Math.round(performance.now() - t0),
  };
}

/** Signature of every input file, so the watcher can tell when something changed. */
export function snapshot(): string {
  const cfg = loadConfig();
  const files = [...findSeasons(cfg).values()].flat().concat(incidentFiles(cfg));
  const extra = join(cfg.dataDir, 'track-overrides.json');
  if (existsSync(extra)) files.push(extra);
  return files.map(f => { try { return `${f}:${fileSig(f)}`; } catch { return f; } }).join('|');
}

export const stamp = () => new Date().toTimeString().slice(0, 8);

export function report(r: CompileResult, log = console.log) {
  log(`[${stamp()}] compiled ${r.seasons} season(s), ${r.races} race(s), ${r.changed} reprocessed, ${r.ms} ms`);
  for (const e of r.errors) log(`  ERROR ${e}`);
}

if (import.meta.main) {
  const r = await compile({ force: process.argv.includes('--force') });
  report(r);
  process.exitCode = r.errors.length ? 1 : 0;
}
