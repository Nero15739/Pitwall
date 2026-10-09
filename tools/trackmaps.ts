/* Track layouts: iRacing's official SVG maps, as mirrored (with lap-distance calibration)
   by the open-source iRaceHUD project. Fetched once per track and cached in .cache/tracks.
   A failed fetch is retried at most once a day and never fails the build. */
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import type { TrackMap } from '../src/lib/shared/types.ts';
import type { Config } from './lib/config.ts';
import { atomicWrite } from './lib/util.ts';

const RETRY_MS = 24 * 3600 * 1000;
const SETTINGS_MAX_AGE_MS = 30 * 24 * 3600 * 1000;

interface CacheEntry { fetched: string; map: TrackMap | null; error?: string }
interface Settings { [id: string]: { offset?: number; direction?: number; customTrackPath?: string } }

async function get(url: string): Promise<string> {
  const res = await fetch(url, { signal: AbortSignal.timeout(10_000) });
  if (!res.ok) throw new Error(`${res.status} ${url}`);
  return res.text();
}

const readJSON = <T>(p: string): T | null => {
  try { return JSON.parse(readFileSync(p, 'utf8')) as T; } catch { return null; }
};

/** First <path d="…">, cut at the first close so we keep a single loop (as iRaceHUD does). */
function firstPath(svg: string): string | null {
  const d = svg.match(/<path[^>]*\sd="([^"]+)"/)?.[1];
  if (!d) return null;
  const loop = d.split(/[zZ]/)[0].replace(/\s+/g, ' ').trim();
  return loop ? loop + 'Z' : null;
}

async function settings(cfg: Config, log: (s: string) => void): Promise<Settings> {
  const p = join(cfg.cacheDir, 'tracks', '_settings.json');
  const cached = readJSON<{ fetched: string; data: Settings }>(p);
  if (cached && Date.now() - Date.parse(cached.fetched) < SETTINGS_MAX_AGE_MS) return cached.data;
  try {
    const data = JSON.parse(await get(cfg.trackMapSource + 'track_settings.json')) as Settings;
    atomicWrite(p, { fetched: new Date().toISOString(), data });
    return data;
  } catch (e) {
    log(`  track settings unavailable (${(e as Error).message}); using cached or defaults`);
    return cached?.data ?? {};
  }
}

export async function loadTrackMaps(cfg: Config, ids: number[], log: (s: string) => void): Promise<Map<number, TrackMap | null>> {
  const out = new Map<number, TrackMap | null>();
  const overrides = readJSON<Record<string, Partial<TrackMap>>>(join(cfg.dataDir, 'track-overrides.json')) ?? {};
  let sets: Settings | null = null;

  for (const id of ids) {
    const p = join(cfg.cacheDir, 'tracks', `${id}.json`);
    let entry = readJSON<CacheEntry>(p);
    const stale = !entry || (!entry.map && Date.now() - Date.parse(entry.fetched) > RETRY_MS);
    if (stale) {
      try {
        sets ??= await settings(cfg, log);
        const [active, sf] = await Promise.all([
          get(`${cfg.trackMapSource}active/${id}.svg`),
          get(`${cfg.trackMapSource}start_finish/${id}.svg`).catch(() => null),
        ]);
        const s = sets[String(id)] ?? {};
        const path = s.customTrackPath ?? firstPath(active);
        if (!path) throw new Error('no path in SVG');
        entry = {
          fetched: new Date().toISOString(),
          map: {
            viewBox: active.match(/viewBox="([^"]+)"/)?.[1] ?? '0 0 1920 1080',
            path,
            sf: sf ? (sf.match(/<path[^>]*\sd="([^"]+)"/)?.[1] ?? null) : null,
            offset: s.offset ?? 0,
            direction: s.direction === -1 ? -1 : 1,
          },
        };
        log(`  fetched track map ${id}`);
      } catch (e) {
        entry = { fetched: new Date().toISOString(), map: entry?.map ?? null, error: (e as Error).message };
        log(`  track map ${id} unavailable: ${entry.error}`);
      }
      atomicWrite(p, entry);
    }
    const map = entry!.map;
    out.set(id, map ? { ...map, ...overrides[String(id)] } as TrackMap : null);
  }
  return out;
}
