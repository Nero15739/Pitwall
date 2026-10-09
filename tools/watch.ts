/* Keeps public/data up to date: recompiles when a race export or harvested incident file
   lands in the data folder. File-system events trigger it straight away; a slow poll catches
   anything OneDrive syncs in without raising an event. One watcher runs at a time.

     node tools/watch.ts          watch, logging to the console
     node tools/watch.ts --log    log to .cache/watcher.log instead (for running hidden)  */
import { appendFileSync, mkdirSync, watch } from 'node:fs';
import { createServer } from 'node:net';
import { join } from 'node:path';
import { compile, report, snapshot, stamp } from './compile.ts';
import { loadConfig } from './lib/config.ts';

const cfg = loadConfig();
const DEBOUNCE_MS = 2000;   // let OneDrive / a copy finish writing
const POLL_MS = 60_000;
const LOCK_PORT = 48766;

const toFile = process.argv.includes('--log');
mkdirSync(cfg.cacheDir, { recursive: true });
const log = (s: string) => (toFile ? appendFileSync(join(cfg.cacheDir, 'watcher.log'), s + '\n') : console.log(s));

// single instance: holding a local port is the lock (released automatically if we die)
const lock = createServer();
lock.once('error', () => { log('Another watcher is already running; exiting.'); process.exit(0); });
lock.listen(LOCK_PORT, '127.0.0.1', () => void start());

let last = '';
let timer: NodeJS.Timeout | undefined;
let running = false, again = false;

async function run(force = false) {
  if (running) { again = true; return; }
  running = true;
  try {
    const now = snapshot();
    if (force || now !== last) {
      report(await compile({ log }), log);
      last = now;
    }
  } catch (e) {
    log(`[${stamp()}] compile failed: ${(e as Error).message}`);
  } finally {
    running = false;
    if (again) { again = false; schedule(); }
  }
}

const schedule = () => { clearTimeout(timer); timer = setTimeout(() => void run(), DEBOUNCE_MS); };

async function start() {
  await run(true);
  mkdirSync(cfg.dataDir, { recursive: true });
  try {
    watch(cfg.dataDir, { recursive: true }, (_e, f) => { if (f && /\.json$/i.test(String(f))) schedule(); });
  } catch (e) {
    log(`File events unavailable (${(e as Error).message}); polling only.`);
  }
  setInterval(() => void run(), POLL_MS).unref();
  log(`Watching ${cfg.dataDir} (Ctrl+C to stop)`);
}
