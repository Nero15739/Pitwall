/* Replay harvester: crash locations and sector splits from a race replay. Launch the replay from
   the iRacing app (Replays), then:

     npm run harvest                     harvest the replay that's open (waits for one to load)
     npm run harvest -- --all            every race still to harvest, one after another as you launch each
     npm run harvest -- --open 89139124  wait for that subsession's replay, then harvest it
     npm run harvest -- --pending        list season races whose replay hasn't been harvested
     npm run harvest -- --force          re-harvest even if a file already exists (with --all: every replay here)
     npm run harvest -- --debug          show each incident as it's spotted, and every step (add to any of the above)

   Every run is logged to .cache/harvest.log with its steps, so a failure can be read back.

   The harvester can't open a replay itself: iRacing only loads one from its own app, which signs
   the launch in. So it waits for you to launch it there.

   One pass: the race plays at 16× while every car is timed across the track's official sector
   lines, placed on a lap chart (who was where at the end of each lap), and watched for incidents
   (leaving the circuit, or rolling backwards in a spin).
   Takes about race length ÷ 16. Output: data/incidents/incidents-<subsession>.json, which the
   watcher compiles automatically. */
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import type { HarvestFile, Season, SeasonIndex } from './lib/types.ts';
import { IncidentDetector } from './incidentdetector.ts';
import { broadcast, Broadcast, type Frame, IRSDK, TrackSurface } from './irsdk.ts';
import { LapChart } from './lapchart.ts';
import { loadConfig } from './lib/config.ts';
import { openLog } from './lib/log.ts';
import { atomicWrite } from './lib/util.ts';
import { pushConfigured, pushFiles, siteStatus } from './push.ts';
import { SplitTimer } from './splittimer.ts';

const cfg = loadConfig();
const args = process.argv.slice(2);
const flag = (f: string) => args.includes(f);
const opt = (f: string) => { const i = args.indexOf(f); return i >= 0 ? args[i + 1] : undefined; };
const sleep = (ms: number) => new Promise(r => setTimeout(r, ms));
const DEBUG = flag('--debug');
const log = openLog(join(cfg.cacheDir, 'harvest.log'), DEBUG);
const say = (s: string) => log.say(s);
/** session time as m:ss */
const clock = (t: number) => `${Math.floor(t / 60)}:${(t % 60).toFixed(1).padStart(4, '0')}`;

const FAST = 16;                 // replay speed (the sim's maximum)
const SETTLE_TICKS = 3;          // the replay must hold still this many reads after a jump
const JUMP_TIMEOUT_MS = 1500;    // first wait for a jump to land; retries wait longer
const JUMP_TRIES = 3;
const READY_GRACE_MS = 1500;     // the replay ignores commands for a moment after loading or seeking
const WAIT_FOR_SIM_MS = 5 * 60 * 1000;
const WAIT_FOR_NEXT_MS = 20 * 60 * 1000; // --all: time to close the sim and launch the next replay

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
/** Subsessions with a replay file on this PC. */
const replaysHere = () => new Set(cfg.replayDir && existsSync(cfg.replayDir)
  ? readdirSync(cfg.replayDir).map(f => f.match(/^subses(\d+)\.rpy$/i)?.[1]).filter(Boolean).map(Number)
  : []);

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
  const replays = replaysHere();
  say(`Replay folder: ${cfg.replayDir ?? '(not found; set "replayDir" in pitwall.config.json)'}\n`);
  for (const r of races) {
    const state = existsSync(outPath(r.subsession)) ? 'harvested'
      : replays.has(r.subsession) ? `ready     → launch subses${r.subsession} from the iRacing app`
      : 'no replay';
    say(`  ${r.season} R${r.round} ${r.track.padEnd(32)} ${String(r.subsession).padEnd(10)} ${state}`);
  }
  if (races.some(r => !existsSync(outPath(r.subsession)) && replays.has(r.subsession)))
    say('\nLaunch a replay from the iRacing app (Replays), then "npm run harvest". Or "npm run harvest -- --all" and launch them one after another.');
}

// ---------------------------------------------------------------- sim helpers
/** Wait for a replay to be loaded in the sim. It has to be launched from the iRacing app: see the top of this file. */
async function waitForReplay(what = 'a replay', timeoutMs = WAIT_FOR_SIM_MS): Promise<IRSDK> {
  const t0 = Date.now();
  let told = false;
  for (;;) {
    const sdk = await IRSDK.open();
    if (sdk?.connected && sdk.sessionInfo()?.WeekendInfo) return sdk;
    sdk?.close();
    if (Date.now() - t0 > timeoutMs) throw new Error(`Timed out waiting for iRacing. Launch ${what} from the iRacing app (Replays) and try again.`);
    if (!told) { say(`Waiting for ${what} to be launched from the iRacing app (Replays)…`); told = true; }
    await sleep(1000);
  }
}

/** Wait for the sim to be closed: another replay can only be loaded into a fresh one. */
async function waitUntilClosed(timeoutMs = WAIT_FOR_NEXT_MS) {
  const t0 = Date.now();
  for (;;) {
    const sdk = await IRSDK.open();
    const up = Boolean(sdk?.connected);
    sdk?.close();
    if (!up) return;
    if (Date.now() - t0 > timeoutMs) throw new Error('Timed out waiting for the sim to be closed.');
    await sleep(1000);
  }
}

const replayFrame = (f: Frame) => f.num('ReplayFrameNum');
const replaySession = (f: Frame) => (f.has('ReplaySessionNum') ? f.num('ReplaySessionNum') : f.num('SessionNum'));
const replayTime = (f: Frame) => (f.has('ReplaySessionTime') ? f.num('ReplaySessionTime') : f.num('SessionTime'));

/** Where the replay is right now, for the log and for error messages. */
function where(sdk: IRSDK) {
  const f = sdk.frame();
  return {
    session: replaySession(f), time: Math.round(replayTime(f) * 10) / 10, frame: replayFrame(f),
    frames_left: f.num('ReplayFrameNumEnd', -1), speed: f.num('ReplayPlaySpeed', NaN), connected: sdk.connected,
  };
}

/** A freshly opened replay ignores commands until it has loaded: wait for that, plus a moment. */
async function waitUntilReady(sdk: IRSDK) {
  const t0 = Date.now();
  let ready = false;
  while (!ready && Date.now() - t0 < 60_000) {
    const tick = sdk.frame().tick;
    sdk.waitForData(250);
    const f = sdk.frame();
    ready = f.num('ReplayFrameNumEnd', 0) > 0 && f.tick !== tick;
    if (!ready) await sleep(250);
  }
  log.detail(`  replay ${ready ? 'ready' : 'still not reporting frames'} after ${((Date.now() - t0) / 1000).toFixed(1)} s: ${JSON.stringify(where(sdk))}`);
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
    const landed = await settle(sdk, from, JUMP_TIMEOUT_MS * (attempt + 1));
    await sleep(READY_GRACE_MS);
    log.detail(`  jump to the race start, try ${attempt + 1}: from frame ${from}, ${landed ? 'landed' : "didn't settle"}: ${JSON.stringify(where(sdk))}`);
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
/** Play the race fast and, from the same samples, time sectors, chart the laps and spot incidents. */
async function fastPass(sdk: IRSDK, raceNum: number, sectors: number[], byIdx: Map<number, Driver>) {
  const laps: NonNullable<HarvestFile['splits']>['laps'] = [];
  const timer = new SplitTimer(sectors);
  const chart = new LapChart();
  const detector = new IncidentDetector();
  const frameOf: number[] = [];
  // how the pass went, for the log and the checks afterwards
  const steps = new Map<number, number>();           // replay frames between samples → how often (16 at 16×)
  const jumps: { at: number; s: number }[] = [];     // the replay clock skipping ahead: nothing is seen in between
  let samples = 0, frameLeft = -1, stoppedAt = -1;
  await broadcast(Broadcast.ReplaySetPlaySpeed, FAST, 0);

  let lastFrame = -1, lastT = -1, stalledSince = Date.now(), lastReport = 0;
  try {
    for (;;) {
      sdk.waitForData(100);
      const f = sdk.frame();
      const fr = replayFrame(f);
      if (replaySession(f) !== raceNum) break;                                 // past the race
      if (fr === lastFrame) {                                                  // end of the replay (or it was stopped)
        if (Date.now() - stalledSince <= 3000) continue;
        frameLeft = f.num('ReplayFrameNumEnd', -1); stoppedAt = lastT;
        break;
      }
      if (lastFrame >= 0) steps.set(fr - lastFrame, (steps.get(fr - lastFrame) ?? 0) + 1);
      lastFrame = fr; stalledSince = Date.now(); samples++;
      const t = replayTime(f);
      if (lastT >= 0 && (t < lastT || t - lastT > 3)) detector.reset();      // a seek or a stale clock
      if (lastT >= 0 && (t < lastT || t - lastT > 1)) {
        jumps.push({ at: t, s: t - lastT });
        if (DEBUG) endProgress();
        log.detail(`    replay clock jumped ${(t - lastT).toFixed(1)} s to ${clock(t)} (frame ${fr})`);
      }
      lastT = t;

      const pct = f.array('CarIdxLapDistPct'), lapNo = f.array('CarIdxLap');
      const pitRoad = f.array('CarIdxOnPitRoad'), surface = f.array('CarIdxTrackSurface');
      const before = detector.incidents.length;
      for (const [idx, drv] of byIdx) {
        const p = Number(pct[idx]), surf = Number(surface[idx]), onPit = Boolean(pitRoad[idx]), lap = Number(lapNo[idx]);
        chart.update(idx, t, p >= 0 ? p : -1, lap);
        if (!(p >= 0) || surf === TrackSurface.NotInWorld) { timer.forget(idx); detector.update(idx, t, -1, -1, false, lap); continue; }
        for (const l of timer.update(idx, p, t, lap, onPit)) {
          laps.push({ car_idx: idx, cust_id: drv.cust_id, lap: l.lap, start: l.start, sectors: l.sectors, lap_time: l.lap_time, pit: l.pit });
        }
        detector.update(idx, t, p, surf, onPit, lap);
      }
      for (let k = before; k < detector.incidents.length; k++) {
        frameOf[k] = fr;
        // as it's spotted (--debug); a spin moments after an off-track is folded into the same incident later
        const e = detector.incidents[k];
        if (DEBUG) endProgress();
        log.detail(`    ${clock(e.t).padStart(7)}  lap ${String(e.lap).padStart(2)}  ${(e.pct * 100).toFixed(1).padStart(5)}%  ${e.kind === 'spin' ? 'spin     ' : 'off track'}  ${byIdx.get(e.car)?.name ?? `car ${e.car}`}`);
      }
      if (Date.now() - lastReport > 1000) {
        progress(`  ${clock(t)} · ${laps.length} laps timed · ${detector.incidents.length} incidents…`);
        lastReport = Date.now();
      }
    }
  } finally {
    await broadcast(Broadcast.ReplaySetPlaySpeed, 0, 0); // never leave the replay running fast
    endProgress();
  }
  const usual = [...steps].sort((a, b) => b[1] - a[1])[0]?.[0] ?? 0;
  return {
    laps, order: chart.order(), incidents: detector.incidents.map((e, k) => ({ ...e, frame: frameOf[k] })),
    samples, usual, jumps, frameLeft, stoppedAt, endedAt: lastT,
  };
}

// ---------------------------------------------------------------- harvest
async function harvest(expectSub?: number) {
  const sdk = await waitForReplay(expectSub ? `subses${expectSub}` : 'a replay');
  let step = 'reading what the sim has loaded';   // what was being done, for the error message and the log
  let homeFrame: number | null = null;
  try {
    const info = sdk.sessionInfo();
    const wk = info.WeekendInfo ?? {};
    const sub = Number(wk.SubSessionID);
    const inReplay = String(wk.SimMode ?? '').toLowerCase() === 'replay' || sdk.frame().get('IsReplayPlaying') === true;
    if (!inReplay) throw new Error('iRacing is in a live session, not a replay. Launch the race replay from the iRacing app (Replays) first.');
    if (!sub) throw new Error('This replay has no subsession ID (a test or offline session), so it can\'t be matched to a race.');
    if (expectSub && sub !== expectSub) throw new Error(`The replay that's open is subsession ${sub}, not ${expectSub}. Close the sim and launch subses${expectSub} from the iRacing app.`);
    if (existsSync(outPath(sub)) && !flag('--force'))
      return say(`Subsession ${sub} is already harvested (${outPath(sub)}). Use --force to redo it.`);

    const sessions: { SessionNum: number; SessionType: string; ResultsLapsComplete?: number }[] = info.SessionInfo?.Sessions ?? [];
    const race = [...sessions].reverse().find(s => /race/i.test(s.SessionType));
    if (!race) throw new Error(`This replay has no race session (it has: ${sessions.map(s => s.SessionType).join(', ') || 'none'}).`);
    log.detail(`  sim: ${wk.SimMode} · sessions ${sessions.map(s => `${s.SessionNum}:${s.SessionType}`).join(' ')} · race is ${race.SessionNum} · ${JSON.stringify(where(sdk))}`);
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
    log.detail(`  track ${wk.TrackID} · ${lengthM ?? '?'} m · sector lines at ${sectors.map(s => s.toFixed(4)).join(', ')} · ${drivers.filter(d => !d.ai).length} drivers + ${drivers.filter(d => d.ai).length} AI`);
    const t0 = Date.now();
    step = 'waiting for the replay to finish loading';
    await waitUntilReady(sdk);
    homeFrame = replayFrame(sdk.frame());
    await broadcast(Broadcast.ReplaySetPlaySpeed, 0, 0);
    step = 'moving the replay to the start of the race';
    if (!(await seekRaceStart(sdk, race.SessionNum))) {
      const at = where(sdk);
      throw new Error(`The replay didn't move to the start of the race after ${JUMP_TRIES} tries (it's in session ${at.session}, the race is session ${race.SessionNum}). `
        + 'Click on the iRacing window once and leave it alone, then run this again; if jumps keep stalling, turn off Replay spooling in iRacing\'s replay options.');
    }

    step = `playing the race at ${FAST}×`;
    say(`  playing the race at ${FAST}× to time ${sectors.length} sectors and spot incidents (about race length ÷ ${FAST})…`);
    if (DEBUG) say('    time     lap    lap%  what       who');
    const pass = await fastPass(sdk, race.SessionNum, sectors, byIdx);
    step = 'checking the pass';
    log.detail(`  pass: ${pass.samples} samples in ${((Date.now() - t0) / 1000).toFixed(0)} s, usually ${pass.usual} frames apart, ended at ${clock(Math.max(0, pass.endedAt))}`
      + (pass.frameLeft >= 0 ? `, replay stopped with ${pass.frameLeft} frames left` : ', ran past the race session'));
    if (!sdk.connected) throw new Error(`iRacing closed while the race was playing (at ${clock(Math.max(0, pass.endedAt))}). Nothing was saved.`);
    // stopped short of the end of the tape: paused, or the sim lost its place
    if (pass.frameLeft > 600) {
      throw new Error(`The replay stopped moving at ${clock(Math.max(0, pass.stoppedAt))} with ${(pass.frameLeft / 60).toFixed(0)} s still to play. `
        + 'Was it paused or the speed changed? Nothing was saved: run this again and leave iRacing alone until it finishes.');
    }
    const warnings: string[] = [];
    if (pass.usual && pass.usual !== FAST) warnings.push(`the replay mostly ran at ${pass.usual}×, not ${FAST}×: was the speed changed while it played?`);
    const skips = pass.jumps.filter(j => j.s > 0);
    if (skips.length) {
      const worst = skips.reduce((a, b) => (b.s > a.s ? b : a));
      warnings.push(`the replay skipped ahead ${skips.length} time(s), ${skips.reduce((a, j) => a + j.s, 0).toFixed(0)} s in all (longest ${worst.s.toFixed(1)} s, to ${clock(worst.at)}): `
        + 'laps and incidents in those stretches are missing. If it keeps happening, turn off Replay spooling in iRacing\'s replay options.');
    }
    if (pass.jumps.some(j => j.s < 0)) warnings.push('the replay clock went backwards during the pass: was it rewound?');
    const raceLaps = Number(race.ResultsLapsComplete);
    if (raceLaps > 0 && pass.order.length < raceLaps) warnings.push(`the lap chart has ${pass.order.length} of the race's ${raceLaps} laps: the replay may not cover the whole race.`);
    if (!pass.order.length) warnings.push('no laps were charted, so the lap-chart awards will skip this round.');

    step = 'saving the result';
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
      lap_order: pass.order,
      splits: { sectors, laps: pass.laps },
    };
    atomicWrite(outPath(sub), file);
    await broadcast(Broadcast.ReplaySetPlayPosition, 0, homeFrame); // back to where you were
    homeFrame = null;

    const humans = incidents.filter(i => !i.ai);
    const top = Object.entries(Object.groupBy(humans, i => i.name))
      .map(([n, xs]) => ({ n, k: xs!.length })).sort((a, b) => b.k - a.k);
    say(`Done in ${((Date.now() - t0) / 1000).toFixed(0)} s: ${pass.laps.length} laps timed, ${pass.order.length} laps charted, ${incidents.length} incidents (${humans.length} by human drivers)`);
    if (top.length) say(`  ${top.slice(0, 6).map(x => `${x.n} ${x.k}`).join(' · ')}`);
    for (const w of warnings) say(`  Warning: ${w}`);
    say(`  Saved ${outPath(sub)}`);
    if (pushConfigured()) {
      step = 'uploading to the site';
      say(`  Uploading to ${cfg.site}…`);
      try {
        const r = await pushFiles([outPath(sub)], { log: say });
        if (r.failed) say('  The upload was refused (see above). The file is saved here: run "npm run push -- --all" to retry.');
      } catch (e) {
        log.fail(e, { step });
        say(`  Upload failed: ${(e as Error).message}. The file is saved here: run "npm run push -- --all" to retry.`);
      }
    } else {
      log.detail('  not uploaded: no site address or API key set (see .env)');
    }
  } catch (e) {
    // say what was being done and where the replay was, and put the replay back
    let at: Record<string, unknown> = {};
    try { at = where(sdk); } catch { /* the sim has gone */ }
    if (homeFrame !== null) await broadcast(Broadcast.ReplaySetPlayPosition, 0, homeFrame).catch(() => {});
    throw Object.assign(new Error(`${(e as Error).message}`, { cause: e }), { context: { step, ...at } });
  } finally {
    sdk.close();
  }
}

/** Every race still to harvest (with --force: every race with a replay here), each as it's launched. */
async function harvestAll() {
  const here = replaysHere();
  const left = new Map((await knownRaces())
    .filter(r => here.has(r.subsession) && (flag('--force') || !existsSync(outPath(r.subsession))))
    .map(r => [r.subsession, r]));
  if (!left.size) return say(flag('--force') ? 'No replays for these races on this PC.' : 'Nothing left to harvest. Add --force to redo the ones already done.');
  const list = () => [...left.values()].map(r => `  ${r.season} R${r.round} ${r.track.padEnd(32)} subses${r.subsession}`).join('\n');
  say(`${left.size} replay(s) to harvest. Launch any of them from the iRacing app (Replays); each is harvested as it loads:\n${list()}\n`);
  while (left.size) {
    const sdk = await waitForReplay('one of them', WAIT_FOR_NEXT_MS);
    const sub = Number(sdk.sessionInfo().WeekendInfo?.SubSessionID);
    sdk.close();
    if (left.has(sub)) {
      await harvest(sub);
      left.delete(sub);
      if (!left.size) break;
      say(`\n${left.size} to go. Close the sim, then launch the next:\n${list()}\n`);
    } else {
      say(`The replay that's open (subsession ${sub || 'unknown'}) isn't one of these. Close the sim, then launch one of:\n${list()}\n`);
    }
    await waitUntilClosed();
  }
  say('\nAll harvested.');
}

// ---------------------------------------------------------------- main
log.detail(`harvest ${args.join(' ') || '(the open replay)'} · node ${process.version} · site ${cfg.site ?? 'not set'} · replays in ${cfg.replayDir ?? 'not found'}`);
try {
  if (flag('--pending')) {
    await pending();
  } else if (flag('--all')) {
    await harvestAll();
  } else if (opt('--open')) {
    const sub = Number(opt('--open'));
    const p = replayPath(sub);
    if (!p || !existsSync(p)) throw new Error(`No replay file for subsession ${sub} in ${cfg.replayDir}`);
    await harvest(sub);
  } else {
    await harvest();
  }
} catch (e) {
  const err = e as Error & { context?: Record<string, unknown> };
  log.fail(err, err.context);
  console.error(`\nHarvest failed${err.context?.step ? ` while ${err.context.step}` : ''}: ${err.message}`);
  console.error(`  The full log is in ${log.file}${DEBUG ? '' : ' (add --debug to watch every step as it runs)'}`);
  if (DEBUG && err.context) console.error(`  The replay was at: ${JSON.stringify(err.context)}`);
  process.exitCode = 1;
}
