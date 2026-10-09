/* Paths and settings from pitwall.config.json. Relative paths resolve against the repo root,
   so the code can move out of OneDrive while the data stays put (or the other way round). */
import { existsSync, readFileSync } from 'node:fs';
import { homedir } from 'node:os';
import { dirname, isAbsolute, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

export const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');

interface RawConfig {
  dataDir?: string;
  outDir?: string;
  replayDir?: string | null;
  port?: number;
  trackMapSource?: string;
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
  };
}
