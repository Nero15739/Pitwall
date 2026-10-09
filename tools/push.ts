/* Push race results and harvested replays to the hosted Pit Wall site over its API.

     npm run push -- --all                 every file under data/ that changed since it was last pushed
     npm run push -- --all --force         everything, changed or not
     npm run push -- <file>...             just these files
     npm run push -- --season "Asia" <file>   put these race results in that season
     npm run push -- --status              what the site holds, and which replays still need harvesting

   Race results go into the season named by their folder under data/ (data/Europe/… → "Europe").
   Needs "site" in pitwall.config.json and an API key, made in the site's admin panel under
   API keys, in PITWALL_API_KEY or the first line of .pitwall-key (git-ignored). The harvester and
   the watcher push new files by themselves once both are set. */
import { createHash } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';
import { basename, isAbsolute, join, relative, resolve, sep } from 'node:path';
import { gzipSync } from 'node:zlib';
import { findSeasons, incidentFiles } from './compile.ts';
import { apiKey, loadConfig } from './lib/config.ts';
import { atomicWrite } from './lib/util.ts';

const cfg = loadConfig();
const STATE = join(cfg.cacheDir, 'pushed.json');

type Kind = 'results' | 'incidents';
const kindOf = (f: string): Kind | null =>
  /^eventresult-.*\.json$/i.test(basename(f)) ? 'results' : /^incidents-\d+\.json$/i.test(basename(f)) ? 'incidents' : null;

/** The season a race result belongs to: its first folder under data/ (null when it lives elsewhere). */
function seasonOf(file: string): string | null {
  const rel = relative(cfg.dataDir, resolve(file));
  if (rel.startsWith('..') || isAbsolute(rel)) return null;
  const top = rel.split(sep)[0];
  return top && top !== basename(file) && top !== 'incidents' ? top : null;
}

export const pushConfigured = () => Boolean(cfg.site && apiKey());

async function api(method: string, path: string, body?: Buffer, headers: Record<string, string> = {}) {
  const key = apiKey();
  if (!cfg.site) throw new Error('Set "site" in pitwall.config.json to your Pit Wall address, e.g. "site": "https://pitwall.example.com".');
  if (!key) throw new Error("No API key. Create one in the site's admin panel (API keys), then save it as the only line of .pitwall-key.");
  const res = await fetch(`${cfg.site}/api/v1${path}`, {
    method, body, headers: { Authorization: `Bearer ${key}`, Accept: 'application/json', ...headers },
    signal: AbortSignal.timeout(120_000),
  });
  const text = await res.text();
  let json: any = null; // eslint-disable-line @typescript-eslint/no-explicit-any
  try { json = JSON.parse(text); } catch { /* not JSON: an HTML error page from the host */ }
  if (!res.ok) throw new Error(json?.error?.message ?? `${res.status} ${res.statusText}${text.startsWith('<') ? ' (the server answered with a web page: check "site")' : ''}`);
  return json;
}

const readState = (): Record<string, string> => { try { return JSON.parse(readFileSync(STATE, 'utf8')); } catch { return {}; } };

/** Push files to the site, skipping any already pushed unchanged. Publishes once at the end. */
export async function pushFiles(files: string[], opts: { force?: boolean; season?: string | null; log?: (s: string) => void } = {}) {
  const log = opts.log ?? console.log;
  const state = readState();
  let sent = 0, failed = 0;
  for (const f of files) {
    const kind = kindOf(f);
    const name = relative(cfg.root, resolve(f));
    if (!kind) { log(`  skip  ${name} (not an eventresult-*.json or incidents-*.json)`); continue; }
    if (!existsSync(f)) { log(`  ✗     ${name}: no such file`); failed++; continue; }
    const body = readFileSync(f);
    const season = kind === 'results' ? (opts.season ?? seasonOf(f)) : null;
    // same bytes, same site, same season = nothing to do
    const mark = `${createHash('sha1').update(body).digest('hex')}@${cfg.site}#${season ?? ''}`;
    const id = resolve(f);
    if (!opts.force && state[id] === mark) continue;
    const q = new URLSearchParams({ compile: '0', filename: basename(f) });
    if (season) q.set('season', season);
    try {
      const r = await api('POST', `/${kind}?${q}`, gzipSync(body), { 'Content-Type': 'application/json', 'Content-Encoding': 'gzip' });
      const s = r.stored;
      log(`  ${s.status.padEnd(9)} ${basename(f)}${s.type === 'result' ? ` → ${s.season}` : ` · ${s.incidents} incidents${s.matched ? '' : ' (race not uploaded yet)'}`}`);
      state[id] = mark;
      if (s.status !== 'unchanged') sent++;
    } catch (e) {
      log(`  ✗     ${basename(f)}: ${(e as Error).message}`);
      failed++;
    }
  }
  atomicWrite(STATE, state);
  if (sent) {
    const c = (await api('POST', '/compile')).compiled;
    log(`  published: ${c.seasons} season(s), ${c.races} race(s) in ${c.ms} ms${c.errors.length ? `, ${c.errors.length} error(s)` : ''}`);
    for (const e of c.errors) log(`    ${e}`);
  }
  return { sent, failed };
}

/** Every result and harvest file under data/. */
export function allDataFiles(): string[] {
  return [...[...findSeasons(cfg).values()].flat(), ...incidentFiles(cfg)];
}

export const pushChanged = (log?: (s: string) => void) => pushFiles(allDataFiles(), { log });

/** What the site holds: seasons, races, and replays still to harvest. */
export async function siteStatus() {
  return api('GET', '/status') as Promise<{
    generated: string | null;
    seasons: { name: string; slug: string; races: { subsession: number; track: string; date: string; harvested: boolean }[] }[];
    pending_harvests: { season: string; round: number; track: string; subsession: number }[];
  }>;
}

// ---------------------------------------------------------------- CLI
if (import.meta.main) {
  const args = process.argv.slice(2);
  const flag = (f: string) => args.includes(f);
  const opt = (f: string) => { const i = args.indexOf(f); return i >= 0 ? args[i + 1] : undefined; };
  try {
    if (flag('--status')) {
      const s = await siteStatus();
      console.log(`${cfg.site} · published ${s.generated ?? 'never'}`);
      for (const season of s.seasons) {
        console.log(`\n  ${season.name}  (${cfg.site}/${season.slug})`);
        season.races.forEach((r, i) => console.log(`    R${i + 1} ${r.track.slice(0, 44).padEnd(44)} ${String(r.subsession).padEnd(10)} ${r.harvested ? 'harvested' : 'replay not harvested'}`));
      }
      if (s.pending_harvests.length) {
        console.log('\nTo harvest (open the replay in iRacing, then):');
        for (const p of s.pending_harvests) console.log(`  npm run harvest -- --open ${p.subsession}    ${p.season} R${p.round} ${p.track}`);
      }
    } else {
      const named = args.filter((a, i) => !a.startsWith('--') && args[i - 1] !== '--season');
      const files = flag('--all') ? allDataFiles() : named;
      if (!files.length) {
        console.log('Nothing to push. Use --all, or name some files. See the top of tools/push.ts.');
      } else {
        console.log(`Pushing to ${cfg.site}`);
        const r = await pushFiles(files, { force: flag('--force'), season: opt('--season') ?? null });
        console.log(r.sent || r.failed ? `${r.sent} sent, ${r.failed} failed` : 'Everything is already up to date.');
        process.exitCode = r.failed ? 1 : 0;
      }
    }
  } catch (e) {
    console.error(`Push failed: ${(e as Error).message}`);
    process.exitCode = 1;
  }
}
