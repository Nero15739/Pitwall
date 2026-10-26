// Plays a probe recording (.cache/probe/<stamp>/) back as a fake live iRacing session under a
// private mapping name, faster than real time, so the logger can be run over a real session again:
//
//   node tools/test/replayprobe.mjs <probeDir> <mapName> <eventName> [speed=20]
//   dist\PitWallLogger\PitWallLogger.exe --headless --map <mapName> --event <eventName> --out <dir>
//
// Telemetry is replayed at the probe's recording rate (10 Hz by default) and every recorded version
// of the session info is published when the session clock reaches it. A snapshot cut short (the
// probe stopped mid-write) is dropped.
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import zlib from 'node:zlib';

const [dir, name, evName, speedArg = '20'] = process.argv.slice(2);
if (!dir || !name || !evName) { console.error('usage: replayprobe.mjs <probeDir> <mapName> <eventName> [speed]'); process.exit(2); }
if (/IRSDKMemMapFileName/i.test(name)) { console.error('Use a private mapping name, never the sim\'s own.'); process.exit(2); }
const speed = Number(speedArg);
const root = resolve(fileURLToPath(import.meta.url), '..', '..', '..');
const koffi = createRequire(join(root, 'package.json'))('koffi');

const gun = f => zlib.gunzipSync(readFileSync(join(dir, f)), { finishFlush: zlib.constants.Z_SYNC_FLUSH }).toString('latin1');
const frames = gun('telemetry.jsonl.gz').trim().split('\n').flatMap(l => { try { return [JSON.parse(l)]; } catch { return []; } });
const snaps = gun('sessioninfo.yaml.gz').split(/^### update /m).filter(Boolean).map(s => {
  const nl = s.indexOf('\n');
  return { t: Number(s.slice(0, nl).match(/session_time ([\d.]+)/)[1]), text: s.slice(nl + 1).trimEnd() + '\n' };
}).filter(s => s.text.includes('\n...'));            // complete documents only
console.log(`${frames.length} telemetry frames (${frames[0].t.toFixed(0)}–${frames.at(-1).t.toFixed(0)} s), ${snaps.length} session info versions, at ${speed}×`);

const k32 = koffi.load('kernel32.dll');
const CreateFileMappingW = k32.func('void * __stdcall CreateFileMappingW(intptr hFile, void *attrs, uint32 protect, uint32 hi, uint32 lo, str16 name)');
const MapViewOfFile = k32.func('void * __stdcall MapViewOfFile(void *h, uint32 access, uint32 hi, uint32 lo, uintptr size)');
const CreateEventW = k32.func('void * __stdcall CreateEventW(void *attrs, bool manual, bool initial, str16 name)');
const SetEvent = k32.func('bool __stdcall SetEvent(void *h)');

const vars = [
  ['SessionTime', 5, 0, 1], ['SessionNum', 2, 8, 1], ['SessionState', 2, 12, 1], ['PlayerCarMyIncidentCount', 2, 16, 1],
  ['CarIdxLapDistPct', 4, 32, 64], ['CarIdxLap', 2, 288, 64], ['CarIdxTrackSurface', 2, 544, 64],
];
const VH = 112, SI = VH + vars.length * 144, YMAX = 256 * 1024, BUF = 800, NBUF = 4;
const B = Array.from({ length: NBUF }, (_, i) => SI + YMAX + i * BUF);
const SIZE = B.at(-1) + BUF;
const mem = new DataView(koffi.view(MapViewOfFile(CreateFileMappingW(-1, null, 4, 0, SIZE, name), 2, 0, 0, SIZE), SIZE));
const ev = CreateEventW(null, false, false, evName);
const i32 = (o, v) => mem.setInt32(o, v, true);
i32(0, 2); i32(4, 1); i32(8, 60); i32(20, SI); i32(24, vars.length); i32(28, VH); i32(32, NBUF); i32(36, BUF);
B.forEach((b, k) => i32(48 + k * 16 + 4, b));
vars.forEach(([n, type, off, count], k) => {
  const o = VH + k * 144;
  i32(o, type); i32(o + 4, off); i32(o + 8, count);
  new TextEncoder().encode(n).forEach((c, j) => mem.setUint8(o + 16 + j, c));
});
let upd = 0;
function publish(text) {
  const b = Buffer.from(text, 'latin1');
  if (b.length >= YMAX) throw new Error('session info too large for the fake mapping');
  for (let j = 0; j < b.length; j++) mem.setUint8(SI + j, b[j]);
  mem.setUint8(SI + b.length, 0);
  i32(16, b.length); i32(12, ++upd);
}

const nap = new Int32Array(new SharedArrayBuffer(4));
const sleep = ms => { if (ms > 0) Atomics.wait(nap, 0, 0, ms); };
let si = 0, tick = 0;
while (si < snaps.length - 1 && snaps[si + 1].t <= frames[0].t) si++;
publish(snaps[si].text);
sleep(1500);   // let the logger attach and read the first session info
const t0 = performance.now(), s0 = frames[0].t;
for (let f = 0; f < frames.length; f++) {
  const fr = frames[f];
  while (si + 1 < snaps.length && snaps[si + 1].t <= fr.t) publish(snaps[++si].text);
  const base = B[++tick % NBUF];
  mem.setFloat64(base, fr.t, true); i32(base + 8, fr.sn); i32(base + 12, fr.ss); i32(base + 16, fr.own?.[0] ?? -1);
  for (let c = 0; c < 64; c++) {
    mem.setFloat32(base + 32 + c * 4, fr.pct[c] ?? -1, true);
    i32(base + 288 + c * 4, fr.lap[c] ?? -1);
    i32(base + 544 + c * 4, fr.surf[c] ?? -1);
  }
  i32(48 + (tick % NBUF) * 16, tick);
  SetEvent(ev);
  if (f % 2000 === 0) process.stdout.write(`\r  session time ${fr.t.toFixed(0)} s`);
  sleep(t0 + ((fr.t - s0) * 1000) / speed - performance.now());
}
i32(4, 0);   // the "sim" closes
console.log('\nDone.');
sleep(3000);
