/* Live-session probe: records what iRacing publishes during a live session, to design the
   incident logger from real data. Waits for the sim, records until stopped (Ctrl+C), and goes
   back to waiting whenever the sim closes.

     npm run probe                    record at 10 Hz (telemetry is read every tick regardless)
     npm run probe -- --rec-hz 20     record positions 20 times a second

   Writes .cache/probe/<date-time>/ (git-ignored: it holds driver names):
     vars.json             every telemetry variable the sim publishes
     sessioninfo.yaml.gz   every version of the session info, each headed with when it changed
     telemetry.jsonl.gz    cars' positions, surface, pit road and the own-incident counters
     events.jsonl          session changes, session info updates, every incident-count change
     summary.json          the answers so far (rewritten every 30 s and at the end)        */
import { appendFileSync, createWriteStream, mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { setImmediate as yieldNow, setTimeout as sleep } from 'node:timers/promises';
import { createGzip } from 'node:zlib';
import { IRSDK, parseSessionInfo, type Frame } from './irsdk.ts';
import { loadConfig } from './lib/config.ts';
import { LiveIncidents } from './liveincidents.ts';

/* eslint-disable @typescript-eslint/no-explicit-any */
const cfg = loadConfig();
const args = process.argv.slice(2);
const opt = (f: string) => { const i = args.indexOf(f); return i >= 0 ? args[i + 1] : undefined; };
const REC_HZ = Number(opt('--rec-hz') ?? 10);
const STALE_MS = 15_000;  // no new tick for this long: the sim has gone

const stamp = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
const dir = opt('--out') ?? join(cfg.cacheDir, 'probe', stamp);
mkdirSync(dir, { recursive: true });
const say = (s: string) => { console.log(`${new Date().toLocaleTimeString()} ${s}`); appendFileSync(join(dir, 'probe.log'), `${new Date().toISOString()} ${s}\n`); };
const event = (o: Record<string, unknown>) => appendFileSync(join(dir, 'events.jsonl'), JSON.stringify({ wall: Date.now(), ...o }) + '\n');
const gz = (name: string) => { const z = createGzip({ level: 6 }); z.pipe(createWriteStream(join(dir, name), { flags: 'a' })); return z; };
const telem = gz('telemetry.jsonl.gz');
const yamlOut = gz('sessioninfo.yaml.gz');
const r5 = (x: number) => Math.round(x * 1e5) / 1e5;

// ---------------------------------------------------------------- stats for the summary
interface SessionStats {
  num: number; type: string; started_wall: number;
  ticks: number; missed_ticks: number; stale_ticks: number; reads: number; first_tick_wall: number; last_tick_wall: number;
  info_updates: number; info_gaps_ms: number[]; parse_ms: number[]; info_bytes: number;
  changed_sections: Record<string, number>;
  drivers: number; others_values: Record<string, number>; others_nonzero: number; others_changes: number;
  results_changes: number; own_changes: number; own_lag_ms: number[]; incidents: number;
}
const sessions: SessionStats[] = [];
let adminLines: string[] = [];
let myEntry: Record<string, unknown> | null = null;

function median(xs: number[]) { if (!xs.length) return null; const s = [...xs].sort((a, b) => a - b); return s[Math.floor(s.length / 2)]; }
function writeSummary() {
  const out = sessions.map(s => {
    const secs = (s.last_tick_wall - s.first_tick_wall) / 1000;
    return {
      session: s.num, type: s.type,
      telemetry: { ticks: s.ticks, ticks_per_s: secs > 0 ? Math.round(s.ticks / secs * 10) / 10 : null, missed_ticks: s.missed_ticks, ticks_with_no_position_change: s.stale_ticks, reads: s.reads },
      session_info: {
        updates: s.info_updates,
        gap_ms: { min: s.info_gaps_ms.length ? Math.min(...s.info_gaps_ms) : null, median: median(s.info_gaps_ms), max: s.info_gaps_ms.length ? Math.max(...s.info_gaps_ms) : null },
        parse_ms_median: median(s.parse_ms), bytes: s.info_bytes, sections_changed: s.changed_sections,
      },
      incident_counts: {
        drivers: s.drivers, others_values_seen: s.others_values, others_with_incidents: s.others_nonzero,
        others_count_changes: s.others_changes, results_count_changes: s.results_changes,
        verdict: s.others_nonzero > 0 || s.others_changes > 0 ? 'VISIBLE: other drivers\' counts are changing'
          : Object.keys(s.others_values).every(v => v === '-1') ? 'HIDDEN: other drivers show -1'
          : 'UNKNOWN: others all 0 so far (hidden, or nobody has had an incident)',
      },
      own_incidents: { changes: s.own_changes, lag_telemetry_to_session_info_ms: s.own_lag_ms },
      placed_incidents: s.incidents,
    };
  });
  writeFileSync(join(dir, 'summary.json'), JSON.stringify({ written: new Date().toISOString(), rec_hz: REC_HZ, admin_lines_in_session_info: adminLines, my_driver_entry: myEntry, sessions: out }, null, 2));
}

// ---------------------------------------------------------------- one connection to the sim
const OWN = ['PlayerCarMyIncidentCount', 'PlayerCarDriverIncidentCount', 'PlayerCarTeamIncidentCount'];

async function record(sdk: IRSDK) {
  writeFileSync(join(dir, 'vars.json'), JSON.stringify(sdk.varList.map(v => ({ name: v.name, type: v.type, count: v.count }))));
  const watch = new LiveIncidents();
  let info: any = null, lastUpd = -1, lastInfoWall = 0, prevSections = new Map<string, string>();
  let sessionNum = -1, st = null as SessionStats | null, myIdx = -1;
  let lastTick = -1, lastTickWall = Date.now(), nextRec = 0, nextStatus = 0, nextSummary = Date.now() + 30_000;
  let prevPct: string | null = null;
  const own = new Map<string, number>();
  const ownRise = new Map<number, number>();         // own count value → wall time it appeared in telemetry
  const counts = { drivers: new Map<number, number>(), results: new Map<number, number>() };
  const names = new Map<number, string>();

  const startSession = (num: number) => {
    sessionNum = num;
    const s = (info?.SessionInfo?.Sessions ?? []).find((x: any) => Number(x.SessionNum) === num);
    st = {
      num, type: String(s?.SessionType ?? '?'), started_wall: Date.now(), ticks: 0, missed_ticks: 0, stale_ticks: 0, reads: 0,
      first_tick_wall: Date.now(), last_tick_wall: Date.now(), info_updates: 0, info_gaps_ms: [], parse_ms: [], info_bytes: 0,
      changed_sections: {}, drivers: 0, others_values: {}, others_nonzero: 0, others_changes: 0, results_changes: 0,
      own_changes: 0, own_lag_ms: [], incidents: 0,
    };
    sessions.push(st);
    watch.reset();
    counts.drivers.clear(); counts.results.clear();
    event({ type: 'session', num, session_type: st.type, sim_mode: info?.WeekendInfo?.SimMode, sub: info?.WeekendInfo?.SubSessionID });
    say(`Session ${num}: ${st.type}`);
  };

  const readInfo = (t: number) => {
    const t0 = performance.now();
    const text = sdk.sessionInfoText();
    info = parseSessionInfo(text);
    const parseMs = performance.now() - t0;
    const upd = sdk.sessionInfoUpdate;
    const now = Date.now();
    yamlOut.write(`### update ${upd} session_time ${t.toFixed(3)} wall ${new Date(now).toISOString()}\n${text}\n`);
    // which top-level sections changed
    const sections = new Map<string, string>();
    for (const part of text.split(/\n(?=[A-Za-z]\w*:)/)) sections.set(part.match(/^\s*([A-Za-z]\w*):/)?.[1] ?? '?', part);
    const changed = [...sections].filter(([k, v]) => prevSections.get(k) !== v).map(([k]) => k);
    prevSections = sections;
    if (!adminLines.length) adminLines = [...new Set(text.split('\n').filter(l => /admin/i.test(l)).map(l => l.trim()))].slice(0, 40);
    myIdx = Number(info?.DriverInfo?.DriverCarIdx ?? -1);
    const drivers: any[] = info?.DriverInfo?.Drivers ?? [];
    for (const d of drivers) names.set(Number(d.CarIdx), String(d.UserName));
    myEntry = drivers.find(d => Number(d.CarIdx) === myIdx) ?? null;
    return { parseMs, changed, now, bytes: text.length };
  };

  const compareCounts = (t: number, source: 'drivers' | 'results', next: Map<number, number>) => {
    const prev = counts[source];
    for (const [car, n] of next) {
      const p = prev.get(car);
      if (p !== undefined && p !== n) {
        event({ type: 'count', source, t, car, name: names.get(car), from: p, to: n, mine: car === myIdx });
        if (car !== myIdx) { if (source === 'drivers') st!.others_changes++; else st!.results_changes++; }
        if (car === myIdx && source === 'drivers' && ownRise.has(n)) { st!.own_lag_ms.push(Date.now() - ownRise.get(n)!); ownRise.delete(n); }
      }
    }
    counts[source] = new Map(next);
    for (const inc of watch.counts(t, source, next)) {
      st!.incidents++;
      event({ type: 'incident', ...inc, name: names.get(inc.car) });
      say(`  ${source}: ${names.get(inc.car) ?? `car ${inc.car}`} ${inc.from}→${inc.to} (${inc.kind})${inc.off ? ` off at ${(inc.off.pct * 100).toFixed(1)}%` : inc.at ? ` at ${(inc.at.pct * 100).toFixed(1)}%` : ''}`);
    }
  };

  const onInfo = (f: Frame) => {
    const t = f.num('SessionTime');
    const r = readInfo(t);
    const num = f.num('SessionNum', -1);
    if (num !== sessionNum || !st) startSession(num);
    const s = st!;
    s.info_updates++; s.parse_ms.push(Math.round(r.parseMs * 10) / 10); s.info_bytes = r.bytes;
    if (lastInfoWall) s.info_gaps_ms.push(r.now - lastInfoWall);
    lastInfoWall = r.now;
    for (const k of r.changed) s.changed_sections[k] = (s.changed_sections[k] ?? 0) + 1;
    event({ type: 'info', upd: sdk.sessionInfoUpdate, t, parse_ms: Math.round(r.parseMs * 10) / 10, bytes: r.bytes, changed: r.changed });

    const drivers: any[] = (info?.DriverInfo?.Drivers ?? []).filter((d: any) => !Number(d.CarIsPaceCar) && !Number(d.IsSpectator));
    s.drivers = drivers.length;
    const dc = new Map<number, number>();
    for (const d of drivers) {
      const n = Number(d.CurDriverIncidentCount ?? d.TeamIncidentCount ?? NaN);
      if (!Number.isFinite(n)) continue;
      dc.set(Number(d.CarIdx), n);
      if (Number(d.CarIdx) !== myIdx) {
        s.others_values[String(n)] = (s.others_values[String(n)] ?? 0) + 0; // keys = values seen
        if (n > 0) s.others_nonzero = Math.max(s.others_nonzero, drivers.filter(x => Number(x.CarIdx) !== myIdx && Number(x.CurDriverIncidentCount) > 0).length);
      }
    }
    compareCounts(t, 'drivers', dc);
    const sess = (info?.SessionInfo?.Sessions ?? []).find((x: any) => Number(x.SessionNum) === sessionNum);
    const rc = new Map<number, number>();
    for (const p of sess?.ResultsPositions ?? []) if (p && Number.isFinite(Number(p.Incidents))) rc.set(Number(p.CarIdx), Number(p.Incidents));
    compareCounts(t, 'results', rc);
  };

  say(`Connected: ${sdk.sessionInfo()?.WeekendInfo?.TrackDisplayName ?? '?'} · ${sdk.sessionInfo()?.WeekendInfo?.SimMode ?? '?'} · tick rate ${sdk.tickRate}/s · ${sdk.varList.length} variables · recording at ${REC_HZ} Hz to ${dir}`);
  for (;;) {
    sdk.waitForData(100);
    await yieldNow();
    if (stopping) break;
    if (!sdk.connected) { say('The sim disconnected.'); break; }
    const f = sdk.frame();
    const now = Date.now();
    if (f.tick === lastTick) {
      if (now - lastTickWall > STALE_MS) { say('No new telemetry for 15 s; treating the sim as closed.'); break; }
      if (st) st.reads++;
      continue;
    }
    if (sdk.sessionInfoUpdate !== lastUpd || f.num('SessionNum', -1) !== sessionNum) { lastUpd = sdk.sessionInfoUpdate; onInfo(f); }
    const s = st!;
    s.reads++; s.ticks++;
    if (lastTick >= 0 && f.tick - lastTick > 1) s.missed_ticks += f.tick - lastTick - 1;
    if (!s.first_tick_wall || s.ticks === 1) s.first_tick_wall = now;
    s.last_tick_wall = now;
    lastTick = f.tick; lastTickWall = now;

    const t = f.num('SessionTime');
    const pct = f.array('CarIdxLapDistPct'), lap = f.array('CarIdxLap'), surf = f.array('CarIdxTrackSurface');
    const key = pct.join(',');
    if (key === prevPct) s.stale_ticks++;
    prevPct = key;
    watch.telemetry(t, pct, lap, surf);

    for (const name of OWN) {
      if (!f.has(name)) continue;
      const v = f.num(name), p = own.get(name);
      if (p !== undefined && p !== v) {
        s.own_changes++;
        event({ type: 'own', t, field: name, from: p, to: v });
        say(`  own ${name}: ${p}→${v}`);
        if (name === 'PlayerCarDriverIncidentCount' || (name === 'PlayerCarMyIncidentCount' && !f.has('PlayerCarDriverIncidentCount'))) ownRise.set(v, now);
      }
      own.set(name, v);
    }

    if (now >= nextRec) {
      nextRec = now + 1000 / REC_HZ;
      telem.write(JSON.stringify({
        w: now, tick: f.tick, t: Math.round(t * 1000) / 1000, sn: f.num('SessionNum'), ss: f.num('SessionState'), sf: f.num('SessionFlags'),
        pct: pct.map(x => r5(Number(x))), lap, surf, mat: f.array('CarIdxTrackSurfaceMaterial'), pit: f.array('CarIdxOnPitRoad').map(Number),
        pos: f.array('CarIdxPosition'), own: OWN.map(n => f.num(n, -99)),
      }) + '\n');
    }
    if (now >= nextStatus) {
      nextStatus = now + 10_000;
      const g = s.info_gaps_ms.slice(-20);
      say(`${s.type}: ${s.ticks} ticks · ${s.info_updates} info updates (median gap ${median(g) ?? '?'} ms) · others' counts ${JSON.stringify(Object.keys(s.others_values))} · ${s.incidents} incidents`);
    }
    if (now >= nextSummary) { nextSummary = now + 30_000; writeSummary(); }
  }
  writeSummary();
}

// ---------------------------------------------------------------- main
let stopping = false;
const stop = () => { stopping = true; };
process.on('SIGINT', stop);
process.on('SIGTERM', stop);

say(`Probe started; waiting for iRacing. Output: ${dir}`);
let told = false;
while (!stopping) {
  const sdk = await IRSDK.open(opt('--map') ?? undefined, opt('--event') ?? undefined);
  if (sdk?.connected && sdk.sessionInfo()?.WeekendInfo) {
    told = false;
    try { await record(sdk); } catch (e) { say(`Error: ${(e as Error).stack}`); writeSummary(); } finally { sdk.close(); }
    continue;
  }
  sdk?.close();
  if (!told) { say('Waiting for the sim…'); told = true; }
  await sleep(2000);
}
writeSummary();
await new Promise<void>(r => { let n = 2; const done = () => { if (--n === 0) r(); }; telem.end(done); yamlOut.end(done); });
say('Probe stopped.');
