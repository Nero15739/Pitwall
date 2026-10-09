/* Offline check of the SDK reader: builds a fake iRacing memory map (header, var headers,
   two telemetry buffers, session YAML) under a private name and reads it back. */
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { IRSDK, parseSessionInfo } from '../irsdk.ts';

const YAML = `---
WeekendInfo:
 TrackName: nurburgring gp
 TrackID: 250
 TrackLength: 5.14 km
 TrackDisplayName: Nürburgring Grand-Prix-Strecke
 TrackConfigName: Grand Prix
 SubSessionID: 89139124
 SimMode: replay

SessionInfo:
 Sessions:
 - SessionNum: 0
   SessionType: Practice
 - SessionNum: 2
   SessionType: Race

DriverInfo:
 DriverCarIdx: 3
 Drivers:
 - CarIdx: 0
   UserName: Pace Car
   UserID: -1
   CarIsPaceCar: 1
   CarIsAI: 0
 - CarIdx: 3
   UserName: *Weird: Name, 'Jr'
   TeamName: @Team, With: Colons
   UserID: 866505
   CarNumber: "12"
   CarIsPaceCar: 0
   CarIsAI: 0
...
`;

test('session YAML with awkward names parses', () => {
  const info = parseSessionInfo(YAML);
  assert.equal(info.WeekendInfo.SubSessionID, 89139124);
  assert.equal(info.WeekendInfo.SimMode, 'replay');
  assert.equal(info.DriverInfo.Drivers[1].UserName, "*Weird: Name, 'Jr'");
  assert.equal(info.DriverInfo.Drivers[1].TeamName, '@Team, With: Colons');
  assert.equal(info.SessionInfo.Sessions[1].SessionType, 'Race');
});

test('reads header, newest buffer, arrays and session info from a mapping', { skip: process.platform !== 'win32' }, async () => {
  const koffi = (await import('koffi')).default;
  const k32 = koffi.load('kernel32.dll');
  const CreateFileMappingW = k32.func('void * __stdcall CreateFileMappingW(intptr hFile, void *attrs, uint32 protect, uint32 hi, uint32 lo, str16 name)');
  const MapViewOfFile = k32.func('void * __stdcall MapViewOfFile(void *h, uint32 access, uint32 hi, uint32 lo, uintptr size)');
  const CloseHandle = k32.func('bool __stdcall CloseHandle(void *h)');

  const name = `Local\\PitWallTest-${process.pid}`;
  const SIZE = 64 * 1024;
  const h = CreateFileMappingW(-1, null, 0x04 /* PAGE_READWRITE */, 0, SIZE, name);
  assert.ok(h, 'CreateFileMappingW failed');
  const p = MapViewOfFile(h, 0x02 /* FILE_MAP_WRITE */, 0, 0, SIZE);
  const mem = new DataView(koffi.view(p, SIZE));

  // vars: name, type, offset, count
  const vars: [string, number, number, number][] = [
    ['ReplayFrameNum', 2, 0, 1],
    ['CamCarIdx', 2, 4, 1],
    ['SessionTime', 5, 8, 1],
    ['CarIdxLapDistPct', 4, 16, 64],
    ['CarIdxTrackSurface', 2, 272, 64],
    ['CarIdxLap', 2, 528, 64],
    ['IsReplayPlaying', 1, 784, 1],
  ];
  const BUF_LEN = 800, VH = 112, SI = VH + vars.length * 144, B0 = 4096, B1 = 8192;
  const yaml = new TextEncoder().encode(YAML.replace('Nürburgring', 'Nurburgring'));
  const i32 = (o: number, v: number) => mem.setInt32(o, v, true);
  i32(0, 2); i32(4, 1); i32(8, 60); i32(12, 1); i32(16, yaml.length); i32(20, SI);
  i32(24, vars.length); i32(28, VH); i32(32, 2); i32(36, BUF_LEN);
  i32(48, 5); i32(52, B0);   // older buffer
  i32(64, 7); i32(68, B1);   // newest buffer
  vars.forEach(([n, type, off, count], k) => {
    const o = VH + k * 144;
    i32(o, type); i32(o + 4, off); i32(o + 8, count);
    new TextEncoder().encode(n).forEach((c, j) => mem.setUint8(o + 16 + j, c));
  });
  yaml.forEach((c, j) => mem.setUint8(SI + j, c));
  for (const [base, frame, car] of [[B0, 999, 1], [B1, 1234, 3]]) {
    i32(base, frame); i32(base + 4, car);
    mem.setFloat64(base + 8, 456.5, true);
    mem.setFloat32(base + 16 + 3 * 4, 0.4321, true);
    i32(base + 272 + 3 * 4, 0); i32(base + 272 + 4 * 4, 3);
    i32(base + 528 + 3 * 4, 7);
    mem.setUint8(base + 784, 1);
  }

  const sdk = await IRSDK.open(name, null);
  assert.ok(sdk);
  try {
    assert.equal(sdk.connected, true);
    const f = sdk.frame();
    assert.equal(f.tick, 7);
    assert.equal(f.num('ReplayFrameNum'), 1234);
    assert.equal(f.num('CamCarIdx'), 3);
    assert.equal(f.num('SessionTime'), 456.5);
    assert.ok(Math.abs(Number(f.array('CarIdxLapDistPct')[3]) - 0.4321) < 1e-6);
    assert.equal(f.array('CarIdxTrackSurface')[3], 0);
    assert.equal(f.array('CarIdxTrackSurface')[4], 3);
    assert.equal(f.array('CarIdxLap')[3], 7);
    assert.equal(f.get('IsReplayPlaying'), true);
    assert.equal(f.has('Nope'), false);
    const info = sdk.sessionInfo();
    assert.equal(info.WeekendInfo.TrackID, 250);
    assert.equal(info.DriverInfo.Drivers[1].UserID, 866505);
  } finally {
    sdk.close();
    CloseHandle(h);
  }
});

test('open returns null when the sim is not running', { skip: process.platform !== 'win32' }, async () => {
  assert.equal(await IRSDK.open(`Local\\PitWallMissing-${process.pid}`, null), null);
});
