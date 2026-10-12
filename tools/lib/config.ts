/* Paths and settings from pitwall.config.json. Relative paths resolve against the repo root,
   so the code can move out of OneDrive while the data stays put (or the other way round).
   This PC's connection to the hosted site (PITWALL_SITE, PITWALL_API_KEY) can live in .env
   beside it (git-ignored); variables already set in the environment win. */
import { existsSync, readFileSync } from 'node:fs';
import { homedir } from 'node:os';
import { dirname, isAbsolute, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

export const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');

const ENV_FILE = join(ROOT, '.env');
if (existsSync(ENV_FILE)) process.loadEnvFile(ENV_FILE);

interface RawConfig {
  dataDir?: string;
  outDir?: string;
  replayDir?: string | null;
  port?: number;
  trackMapSource?: string;
  site?: string | null;
}

export interface Config {
  root: string;
  dataDir: string;        // season folders + incidents/ + track-overrides.json
  incidentsDir: string;   // harvested replay incidents
  outDir: string;         // what the web server serves
  appOut: string;         // built UI
  dataOut: string;        // compiled JSON
  cacheDir: string;
  replayDir: string | null;
  port: number;
  trackMapSource: string;
  site: string | null;    // the hosted site (PHP) that tools push to, e.g. https://pitwall.example.com
}

/** API key for the hosted site: PITWALL_API_KEY (environment or .env), else the first line of .pitwall-key (git-ignored). */
export function apiKey(): string | null {
  if (process.env.PITWALL_API_KEY?.trim()) return process.env.PITWALL_API_KEY.trim();
  const f = join(ROOT, '.pitwall-key');
  return existsSync(f) ? readFileSync(f, 'utf8').split(/\r?\n/)[0].trim() || null : null;
}

const abs = (p: string) => (isAbsolute(p) ? p : join(ROOT, p));

/** iRacing keeps replays in Documents\iRacing\replay; Documents is often redirected into OneDrive. */
function findReplayDir(): string | null {
  const home = homedir();
  const candidates = [
    process.env.OneDrive && join(process.env.OneDrive, 'Documents', 'iRacing', 'replay'),
    process.env.OneDriveCommercial && join(process.env.OneDriveCommercial, 'Documents', 'iRacing', 'replay'),
    process.env.OneDriveConsumer && join(process.env.OneDriveConsumer, 'Documents', 'iRacing', 'replay'),
    join(home, 'Documents', 'iRacing', 'replay'),
  ].filter(Boolean) as string[];
  return candidates.find(p => existsSync(p)) ?? null;
}

export function loadConfig(): Config {
  const file = join(ROOT, 'pitwall.config.json');
  const raw: RawConfig = existsSync(file) ? JSON.parse(readFileSync(file, 'utf8')) : {};
  const dataDir = abs(raw.dataDir ?? 'data');
  const outDir = abs(raw.outDir ?? 'public');
  return {
    root: ROOT,
    dataDir,
    incidentsDir: join(dataDir, 'incidents'),
    outDir,
    appOut: join(outDir, 'app'),
    dataOut: join(outDir, 'data'),
    cacheDir: join(ROOT, '.cache'),
    replayDir: raw.replayDir ? abs(raw.replayDir) : findReplayDir(),
    port: raw.port ?? 8080,
    trackMapSource: raw.trackMapSource ?? 'https://raw.githubusercontent.com/xikxp1/iRaceHUD/main/static/track_info_data/',
    site: (process.env.PITWALL_SITE || raw.site)?.replace(/\/+$/, '') || null,
  };
}
