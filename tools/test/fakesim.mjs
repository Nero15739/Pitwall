// Fake live iRacing session under a private mapping name, for testing the probe/logger offline.
// node tools/test/fakesim.mjs <repoRoot> <mapName> <eventName> [seconds=6] [Practice|Race]
// Then point the probe or the logger at it: --map <mapName> --event <eventName> (never the real names).
// Three cars: car 2 leaves the track at 6 s (+1 at 7 s), the player +2 at 6.7 s (telemetry) / 7.7 s, car 3 +4 at 7.7 s.
// Race: the chequered flag shows for the last second.
import { createRequire } from 'node:module';
const [root, name, evName, secs = '6', stype = 'Practice'] = process.argv.slice(2);
const require = createRequire(root + '/package.json');
const koffi = require('koffi');
const k32 = koffi.load('kernel32.dll');
const CreateFileMappingW = k32.func('void * __stdcall CreateFileMappingW(intptr hFile, void *attrs, uint32 protect, uint32 hi, uint32 lo, str16 name)');
const MapViewOfFile = k32.func('void * __stdcall MapViewOfFile(void *h, uint32 access, uint32 hi, uint32 lo, uintptr size)');
const CreateEventW = k32.func('void * __stdcall CreateEventW(void *attrs, bool manual, bool initial, str16 name)');
const SetEvent = k32.func('bool __stdcall SetEvent(void *h)');
const SIZE = 256 * 1024;
const h = CreateFileMappingW(-1, null, 4, 0, SIZE, name);
const mem = new DataView(koffi.view(MapViewOfFile(h, 2, 0, 0, SIZE), SIZE));
const ev = CreateEventW(null, false, false, evName);
const vars = [
  ['SessionTime', 5, 0, 1], ['SessionNum', 2, 8, 1], ['SessionState', 2, 12, 1], ['SessionFlags', 3, 16, 1],
  ['CarIdxLapDistPct', 4, 32, 64], ['CarIdxLap', 2, 288, 64], ['CarIdxTrackSurface', 2, 544, 64],
  ['CarIdxTrackSurfaceMaterial', 2, 800, 64], ['CarIdxOnPitRoad', 1, 1056, 64], ['CarIdxPosition', 2, 1120, 64],
  ['PlayerCarMyIncidentCount', 2, 1376, 1], ['PlayerCarDriverIncidentCount', 2, 1380, 1], ['PlayerCarTeamIncidentCount', 2, 1384, 1],
];
const BUF = 1400, VH = 112, SI = VH + vars.length * 144, B = [16384, 20480], YMAX = 16384 - SI;
const i32 = (o, v) => mem.setInt32(o, v, true);
i32(0, 2); i32(4, 1); i32(8, 60); i32(20, SI); i32(24, vars.length); i32(28, VH); i32(32, 2); i32(36, BUF);
i32(52, B[0]); i32(68, B[1]);
vars.forEach(([n, type, off, count], k) => { const o = VH + k * 144; i32(o, type); i32(o + 4, off); i32(o + 8, count); new TextEncoder().encode(n).forEach((c, j) => mem.setUint8(o + 16 + j, c)); });
let upd = 0;
const cnt = { 1: 0, 2: 0, 3: 0 };
function yaml() {
  const d = (i, n, id) => ` - CarIdx: ${i}\n   UserName: ${n}\n   UserID: ${id}\n   CarIsPaceCar: ${i === 0 ? 1 : 0}\n   IsSpectator: 0\n   CurDriverIncidentCount: ${i === 0 ? 0 : cnt[i]}\n   TeamIncidentCount: ${i === 0 ? 0 : cnt[i]}\n`;
  const y = `---\nWeekendInfo:\n TrackDisplayName: Fake Ring\n SubSessionID: 0\n SimMode: full\n\nSessionInfo:\n Sessions:\n - SessionNum: 0\n   SessionType: ${stype}\n   ResultsPositions:\n   - CarIdx: 2\n     Incidents: ${cnt[2]}\n   - CarIdx: 3\n     Incidents: ${cnt[3]}\n\nDriverInfo:\n DriverCarIdx: 1\n Drivers:\n${d(0, 'Pace Car', -1)}${d(1, 'Me Driver', 1)}${d(2, 'Other One', 2)}${d(3, 'Other Two', 3)}\n...\n`;
  const b = new TextEncoder().encode(y);
  for (let j = 0; j < YMAX; j++) mem.setUint8(SI + j, j < b.length ? b[j] : 0);
  i32(16, b.length); i32(12, ++upd);
}
yaml();
let tick = 0;
const total = Number(secs) * 60;
const timer = setInterval(() => {
  tick++;
  const t = tick / 60, base = B[tick % 2];
  mem.setFloat64(base, t, true); i32(base + 8, 0); i32(base + 12, tick > total - 60 ? 5 : 4);
  for (const car of [1, 2, 3]) {
    mem.setFloat32(base + 32 + car * 4, ((t * 0.01 + car * 0.1) % 1), true);
    i32(base + 288 + car * 4, 1);
    i32(base + 544 + car * 4, car === 2 && tick > 360 && tick < 390 ? 0 : 3);  // car 2 off track
  }
  for (let c = 4; c < 64; c++) mem.setFloat32(base + 32 + c * 4, -1, true);
  i32(base + 1380, tick > 400 ? 2 : 0); i32(base + 1376, tick > 400 ? 2 : 0);
  i32(48 + (tick % 2) * 16, tick);
  if (tick === 420) { cnt[2] = 1; yaml(); }         // car 2's off-track shows up a second later
  if (tick === 460) { cnt[1] = 2; cnt[3] = 4; yaml(); }  // my 2x (telemetry at 100) and car 3 contact
  else if (tick % 60 === 0) yaml();
  SetEvent(ev);
  if (tick >= total) { i32(4, 0); clearInterval(timer); setTimeout(() => process.exit(0), 3000); }
}, 16);
