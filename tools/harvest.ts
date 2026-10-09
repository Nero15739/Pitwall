/* Replay harvester: crash locations and sector splits from a race replay. Open the replay in
   iRacing (or use --open), then:

     npm run harvest                     harvest the replay that's open
     npm run harvest -- --open 89139124  open that subsession's replay, then harvest it
     npm run harvest -- --pending        list season races whose replay hasn't been harvested
     npm run harvest -- --force          re-harvest even if a file already exists

   One pass: the race plays at 16× while every car is timed across the track's official sector
   lines and watched for incidents (leaving the circuit, or rolling backwards in a spin).
   Takes about race length ÷ 16. Output: data/incidents/incidents-<subsession>.json, which the
   watcher compiles automatically. */
import { spawn } from 'node:child_process';
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import type { HarvestFile, Season, SeasonIndex } from './lib/types.ts';
import { IncidentDetector } from './incidentdetector.ts';
import { broadcast, Broadcast, type Frame, IRSDK, TrackSurface } from './irsdk.ts';
import { loadConfig } from './lib/config.ts';
import { atomicWrite } from './lib/util.ts';
import { pushConfigured, pushFiles, siteStatus } from './push.ts';
import { SplitTimer } from './splittimer.ts';

const cfg = loadConfig();
const args = process.argv.slice(2);
const flag = (f: string) => args.includes(f);
const opt = (f: string) => { const i = args.indexOf(f); return i >= 0 ? args[i + 1] : undefined; };
const sleep = (ms: number) => new Promise(r => setTimeout(r, ms));
const say = (s: string) => console.log(s);

const FAST = 16;                 // replay speed (the sim's maximum)
const SETTLE_TICKS = 3;          // the replay must hold still this many reads after a jump
const JUMP_TIMEOUT_MS = 1500;    // first wait for a jump to land; retries wait longer
const JUMP_TRIES = 3;
const READY_GRACE_MS = 1500;     // the replay ignores commands for a moment after loading or seeking
const WAIT_FOR_SIM_MS = 5 * 60 * 1000;

type Driver = HarvestFile['drivers'][number];

// ---------------------------------------------------------------- season races (from compiled data)
interface RaceRef { season: string; round: number; track: string; subsession: number }
function seasonRaces(): RaceRef[] {
  const idxPath = join(cfg.dataOut, 'seasons.json');
  if (!existsSync(idxPath)) return [];
  const idx = JSON.parse(readFileSync(idxPath, 'utf8')) as SeasonIndex;
  return idx.seasons.flatMap(s => {
    const season = JSON.parse(readFileSync(join(cfg.dataOut, s.file), 'utf8')) as Season;
    return season.rounds.map(r => ({ season: s.name, round: r.round, track: r.track_short, subsession: r.subsession }));
  });
}

const outPath = (sub: number) => join(cfg.incidentsDir, `incidents-${sub}.json`);
const replayPath = (sub: number) => (cfg.replayDir ? join(cfg.replayDir, `subses${sub}.rpy`) : null);

/** The hosted site's races when it's set up (it's the source of truth), else the local compile. */
async function knownRaces(): Promise<RaceRef[]> {
  if (pushConfigured()) {
    try {
      const s = await siteStatus();
      return s.seasons.flatMap(season => season.races.map((r, i) => ({ season: season.name, round: i + 1, track: r.track, subsession: r.subsession })));
    } catch (e) {
      say(`Couldn't reach ${cfg.site} (${(e as Error).message}); using local data.\n`);
    }
  }
  return seasonRaces();
}

async function pending() {
  const races = await knownRaces();
  if (!races.length) return say('No season data yet. Upload a race to the site, or run "npm run compile".');
  const replays = new Set(cfg.replayDir && existsSync(cfg.replayDir)
    ? readdirSync(cfg.replayDir).map(f => f.match(/^subses(\d+)\.rpy$/i)?.[1]).filter(Boolean).map(Number)
    : []);
  say(`Replay folder: ${cfg.replayDir ?? '(not found; set "replayDir" in pitwall.config.json)'}\n`);
  for (const r of races) {
    const state = existsSync(outPath(r.subsession)) ? 'harvested'
      : replays.has(r.subsession) ? 'ready     → npm run harvest -- --open ' + r.subsession
      : 'no replay';
    say(`  ${r.season} R${r.round} ${r.track.padEnd(32)} ${String(r.subsession).padEnd(10)} ${state}`);
  }
}

// ---------------------------------------------------------------- sim helpers
async function waitForReplay(): Promise<IRSDK> {
  const t0 = Date.now();
  let told = false;
  for (;;) {
    const sdk = await IRSDK.open();
    if (sdk?.connected && sdk.sessionInfo()?.WeekendInfo) return sdk;
    sdk?.close();
    if (Date.now() - t0 > WAIT_FOR_SIM_MS) throw new Error('Timed out waiting for iRacing. Open a replay and try again.');
    if (!told) { say('Waiting for iRacing with a replay open…'); told = true; }
    await sleep(1000);
  }
}

const replayFrame = (f: Frame) => f.num('ReplayFrameNum');
const replaySession = (f: Frame) => (f.has('ReplaySessionNum') ? f.num('ReplaySessionNum') : f.num('SessionNum'));
const replayTime = (f: Frame) => (f.has('ReplaySessionTime') ? f.num('ReplaySessionTime') : f.num('SessionTime'));

/** A freshly opened replay ignores commands until it has loaded: wait for that, plus a moment. */
async function waitUntilReady(sdk: IRSDK) {
  const t0 = Date.now();
  while (Date.now() - t0 < 60_000) {
    const tick = sdk.frame().tick;
    sdk.waitForData(250);
    const f = sdk.frame();
    if (f.num('ReplayFrameNumEnd', 0) > 0 && f.tick !== tick) break;
    await sleep(250);
  }
  await sleep(READY_GRACE_MS);
}

/** Wait until the replay's frame moves off `from` and then holds still for a few ticks. */
async function settle(sdk: IRSDK, from: number, timeoutMs: number): Promise<boolean> {
  const t0 = Date.now();
  let last = from, still = 0;
  while (Date.now() - t0 < timeoutMs) {
    sdk.waitForData(50) || (await sleep(16));
    const fr = replayFrame(sdk.frame());
    if (fr !== from && fr === last) { if (++still >= SETTLE_TICKS) return true; } else still = 0;
    last = fr;
  }
  return false;
}

/** Seek to the start of the race. The sim sometimes drops a seek, so confirm and resend. */
async function seekRaceStart(sdk: IRSDK, raceNum: number): Promise<boolean> {
  for (let attempt = 0; attempt < JUMP_TRIES; attempt++) {
    const from = replayFrame(sdk.frame());
    await broadcast(Broadcast.ReplaySearchSessionTime, raceNum, 0);
    await settle(sdk, from, JUMP_TIMEOUT_MS * (attempt + 1));
    await sleep(READY_GRACE_MS);
    if (replaySession(sdk.frame()) === raceNum) return true;
  }
  return false;
}

/** Sector lines as lap distance, from the session's SplitTimeInfo (thirds if the track has none). */
function sectorStarts(info: any): number[] { // eslint-disable-line @typescript-eslint/no-explicit-any
  const s = (info.SplitTimeInfo?.Sectors ?? []).map((x: { SectorStartPct: number }) => Number(x.SectorStartPct))
    .filter((p: number) => p >= 0 && p < 1).sort((a: number, b: number) => a - b);
  return s.length && s[0] === 0 ? s : [0, 1 / 3, 2 / 3];
}

const progress = (s: string) => process.stdout.write(`\r${s.padEnd(72)}`);
const endProgress = () => process.stdout.write(`\r${' '.repeat(72)}\r`);

// ---------------------------------------------------------------- the 16× pass
/** Play the race fast and, from the same samples, time sectors and spot incidents. */
async function fastPass(sdk: IRSDK, raceNum: number, sectors: number[], byIdx: Map<number, Driver>) {
  const laps: NonNullable<HarvestFile['splits']>['laps'] = [];
  const timer = new SplitTimer(sectors);
  const detector = new IncidentDetector();
  const frameOf: number[] = [];
  await broadcast(Broadcast.ReplaySetPlaySpeed, FAST, 0);

  let lastFrame = -1, lastT = -1, stalledSince = Date.now(), lastReport = 0;
  for (;;) {
    sdk.waitForData(100);
    const f = sdk.frame();
    const fr = replayFrame(f);
    if (replaySession(f) !== raceNum) break;                                 // past the race
    if (fr === lastFrame) { if (Date.now() - stalledSince > 3000) break; continue; } // end of the replay
    lastFrame = fr; stalledSince = Date.now();
    const t = replayTime(f);
    if (lastT >= 0 && (t < lastT || t - lastT > 3)) detector.reset();      // a seek or a stale clock
    lastT = t;

    const pct = f.array('CarIdxLapDistPct'), lapNo = f.array('CarIdxLap');
    const pitRoad = f.array('CarIdxOnPitRoad'), surface = f.array('CarIdxTrackSurface');
    const before = detector.incidents.length;
    for (const [idx, drv] of byIdx) {
      const p = Number(pct[idx]), surf = Number(surface[idx]), onPit = Boolean(pitRoad[idx]), lap = Number(lapNo[idx]);
      if (!(p >= 0) || surf === TrackSurface.NotInWorld) { timer.forget(idx); detector.update(idx, t, -1, -1, false, lap); continue; }
      for (const l of timer.update(idx, p, t, lap, onPit)) {
        laps.push({ car_idx: idx, cust_id: drv.cust_id, lap: l.lap, start: l.start, sectors: l.sectors, lap_time: l.lap_time, pit: l.pit });
      }
      detector.update(idx, t, p, surf, onPit, lap);
    }
    for (let k = before; k < detector.incidents.length; k++) frameOf[k] = fr;
    if (Date.now() - lastReport > 1000) {
      progress(`  ${laps.length} laps timed · ${detector.incidents.length} incidents…`);
      lastReport = Date.now();
    }
  }
  await broadcast(Broadcast.ReplaySetPlaySpeed, 0, 0);
  endProgress();
  return { laps, incidents: detector.incidents.map((e, k) => ({ ...e, frame: frameOf[k] })) };
}

// ---------------------------------------------------------------- harvest
async function harvest(expectSub?: number) {
  const sdk = await waitForReplay();
  try {
    const info = sdk.sessionInfo();
    const wk = info.WeekendInfo ?? {};
    const sub = Number(wk.SubSessionID);
    const inReplay = String(wk.SimMode ?? '').toLowerCase() === 'replay' || sdk.frame().get('IsReplayPlaying') === true;
    if (!inReplay) throw new Error('iRacing is in a live session, not a replay. Open the race replay first.');
    if (!sub) throw new Error('This replay has no subsession ID (test or offline session).');
    if (expectSub && sub !== expectSub) throw new Error(`The open replay is subsession ${sub}, expected ${expectSub}.`);
    if (existsSync(outPath(sub)) && !flag('--force'))
      return say(`Subsession ${sub} is already harvested (${outPath(sub)}). Use --force to redo it.`);

    const sessions: { SessionNum: number; SessionType: string }[] = info.SessionInfo?.Sessions ?? [];
    const race = [...sessions].reverse().find(s => /race/i.test(s.SessionType));
    if (!race) throw new Error('No race session in this replay.');
    const drivers: Driver[] = (info.DriverInfo?.Drivers ?? [])
      .filter((d: any) => !d.CarIsPaceCar && !d.IsSpectator) // eslint-disable-line @typescript-eslint/no-explicit-any
      .map((d: any) => ({ // eslint-disable-line @typescript-eslint/no-explicit-any
        car_idx: Number(d.CarIdx), cust_id: Number(d.UserID), name: String(d.UserName),
        ai: Number(d.CarIsAI) === 1, number: String(d.CarNumber ?? ''),
      }));
    const byIdx = new Map(drivers.map(d => [d.car_idx, d]));
    const len = String(wk.TrackLength ?? '').match(/([\d.]+)\s*(km|mi)/i);
    const lengthM = len ? Math.round(parseFloat(len[1]) * (len[2].toLowerCase() === 'mi' ? 1609.344 : 1000)) : null;
    const sectors = sectorStarts(info);

    const cfgName = String(wk.TrackConfigName ?? '').trim();
    say(`Harvesting ${wk.TrackDisplayName}${cfgName ? ` (${cfgName})` : ''} · subsession ${sub} · ${drivers.length} cars`);
    const t0 = Date.now();
    await waitUntilReady(sdk);
    const homeFrame = replayFrame(sdk.frame());
    await broadcast(Broadcast.ReplaySetPlaySpeed, 0, 0);
    if (!(await seekRaceStart(sdk, race.SessionNum)))
      throw new Error("Couldn't move the replay to the start of the race. Check the replay includes the race, then try again.");

    say(`  playing the race at ${FAST}× to time ${sectors.length} sectors and spot incidents (about race length ÷ ${FAST})…`);
    const pass = await fastPass(sdk, race.SessionNum, sectors, byIdx);
    const incidents: HarvestFile['incidents'] = pass.incidents.map(e => {
      const d = byIdx.get(e.car)!;
      return {
        frame: e.frame, t: Math.round(e.t * 100) / 100, lap: e.lap, pct: Math.round(e.pct * 1e5) / 1e5,
        car_idx: e.car, cust_id: d.cust_id, name: d.name, ai: d.ai, off_track: e.off, kind: e.kind,
      };
    });

    const file: HarvestFile = {
      version: 1,
      subsession: sub,
      track: { id: Number(wk.TrackID), name: String(wk.TrackDisplayName ?? ''), config: cfgName, length_m: lengthM },
      harvested_at: new Date().toISOString(),
      method: 'telemetry',
      session: { num: race.SessionNum, type: race.SessionType },
      drivers,
      incidents,
      splits: { sectors, laps: pass.laps },
    };
    atomicWrite(outPath(sub), file);
    await broadcast(Broadcast.ReplaySetPlayPosition, 0, homeFrame); // back to where you were

    const humans = incidents.filter(i => !i.ai);
    const top = Object.entries(Object.groupBy(humans, i => i.name))
      .map(([n, xs]) => ({ n, k: xs!.length })).sort((a, b) => b.k - a.k);
    say(`Done in ${((Date.now() - t0) / 1000).toFixed(0)} s: ${pass.laps.length} laps timed, ${incidents.length} incidents (${humans.length} by human drivers)`);
    if (top.length) say(`  ${top.slice(0, 6).map(x => `${x.n} ${x.k}`).join(' · ')}`);
    say(`  Saved ${outPath(sub)}`);
    if (pushConfigured()) {
      say(`  Uploading to ${cfg.site}…`);
      try {
        await pushFiles([outPath(sub)], { log: say });
      } catch (e) {
        say(`  Upload failed: ${(e as Error).message}. Run "npm run push -- --all" to retry.`);
      }
    }
  } finally {
    sdk.close();
  }
}

// ---------------------------------------------------------------- main
try {
  if (flag('--pending')) {
    await pending();
  } else if (opt('--open')) {
    const sub = Number(opt('--open'));
    const p = replayPath(sub);
    if (!p || !existsSync(p)) throw new Error(`No replay file for subsession ${sub} in ${cfg.replayDir}`);
    say(`Opening ${p} in iRacing…`);
    spawn('explorer.exe', [p], { detached: true, stdio: 'ignore' }).unref(); // opens with the .rpy file association
    await harvest(sub);
  } else {
    await harvest();
  }
} catch (e) {
  console.error(`Harvest failed: ${(e as Error).message}`);
  process.exitCode = 1;
}
