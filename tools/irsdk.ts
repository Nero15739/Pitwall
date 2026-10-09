/* Minimal iRacing SDK client: reads the sim's shared memory (telemetry + session info)
   and sends broadcast messages (replay and camera control). Windows only.

   Memory layout (irsdk_defines.h):
     header  112 B  ver, status, tickRate, sessionInfoUpdate, sessionInfoLen, sessionInfoOffset,
                    numVars, varHeaderOffset, numBuf, bufLen, pad[2], varBuf[4]{tickCount, bufOffset, pad[2]}
     varHeader 144 B  type, offset, count, countAsTime(+3 pad), name[32], desc[64], unit[32]          */
import { parse as parseYaml } from 'yaml';

export const MAP_NAME = 'Local\\IRSDKMemMapFileName';
export const DATA_EVENT = 'Local\\IRSDKDataValidEvent';
const STATUS_CONNECTED = 1;
const HEADER_LEN = 112;
const VAR_HEADER_LEN = 144;
const TYPE_SIZE = [1, 1, 4, 4, 4, 8]; // char, bool, int, bitfield, float, double

/** irsdk_BroadcastMsg (the ones we use) */
export const Broadcast = {
  ReplaySetPlaySpeed: 3,       // var1 = speed, var2 = slow motion
  ReplaySetPlayPosition: 4,    // var1 = position mode (0 = from start), var2 = frame
  ReplaySearchSessionTime: 12, // var1 = session number, var2 = session time in ms
} as const;
/** irsdk_TrkLoc */
export const TrackSurface = { NotInWorld: -1, OffTrack: 0, InPitStall: 1, ApproachingPits: 2, OnTrack: 3 } as const;

export interface VarHeader { type: number; offset: number; count: number; name: string }

/* eslint-disable @typescript-eslint/no-explicit-any */
type Fn = (...args: any[]) => any;
interface Win32 {
  OpenFileMappingW: Fn; MapViewOfFile: Fn; UnmapViewOfFile: Fn; CloseHandle: Fn;
  OpenEventW: Fn; WaitForSingleObject: Fn; RegisterWindowMessageW: Fn; SendNotifyMessageW: Fn;
  view: (ptr: unknown, len: number) => ArrayBuffer;
}

let win: Win32 | null = null;
async function win32(): Promise<Win32> {
  if (win) return win;
  if (process.platform !== 'win32') throw new Error('The iRacing SDK is only available on Windows.');
  let koffi: any;
  try { koffi = (await import('koffi')).default; } catch {
    throw new Error('koffi is not installed. Run "npm install" on the iRacing PC.');
  }
  const k32 = koffi.load('kernel32.dll');
  const u32 = koffi.load('user32.dll');
  win = {
    OpenFileMappingW: k32.func('void * __stdcall OpenFileMappingW(uint32 access, bool inherit, str16 name)'),
    MapViewOfFile: k32.func('void * __stdcall MapViewOfFile(void *h, uint32 access, uint32 hi, uint32 lo, uintptr size)'),
    UnmapViewOfFile: k32.func('bool __stdcall UnmapViewOfFile(void *p)'),
    CloseHandle: k32.func('bool __stdcall CloseHandle(void *h)'),
    OpenEventW: k32.func('void * __stdcall OpenEventW(uint32 access, bool inherit, str16 name)'),
    WaitForSingleObject: k32.func('uint32 __stdcall WaitForSingleObject(void *h, uint32 ms)'),
    RegisterWindowMessageW: u32.func('uint32 __stdcall RegisterWindowMessageW(str16 name)'),
    SendNotifyMessageW: u32.func('bool __stdcall SendNotifyMessageW(uintptr hwnd, uint32 msg, uintptr wParam, intptr lParam)'),
    view: koffi.view,
  };
  return win;
}

const FILE_MAP_READ = 0x4;
const SYNCHRONIZE = 0x00100000;

/** iRacing's YAML has unquoted free-text values (names with ':' or leading '*', '@', ',').
    Quote those fields before parsing, as pyirsdk does. */
const FREE_TEXT = /^(\s*(?:- )?(?:UserName|TeamName|AbbrevName|Initials|ClubName|DivisionName|FlairName|CarScreenName|CarScreenNameShort|CarClassShortName|CarDesignStr|HelmetDesignStr|SuitDesignStr|CarNumberDesignStr|TrackDisplayName|TrackDisplayShortName|TrackConfigName|TrackCity|TrackCountry|SeriesName|SessionName|EventType): )(.*)$/gm;
export function parseSessionInfo(text: string): any {
  const clean = text
    .replace(/[\x00-\x08\x0b\x0c\x0e-\x1f]/g, '')
    .replace(FREE_TEXT, (_m, key: string, val: string) => `${key}'${val.trim().replace(/'/g, "''")}'`);
  return parseYaml(clean, { uniqueKeys: false, strict: false }) ?? {};
}

/** One consistent snapshot of the telemetry variables. */
export class Frame {
  private dv: DataView;
  private vars: Map<string, VarHeader>;
  readonly tick: number;
  constructor(vars: Map<string, VarHeader>, buf: Uint8Array, tick: number) {
    this.vars = vars;
    this.tick = tick;
    this.dv = new DataView(buf.buffer, buf.byteOffset, buf.byteLength);
  }
  has(name: string) { return this.vars.has(name); }
  private read(v: VarHeader, i: number): number | boolean {
    const o = v.offset + i * TYPE_SIZE[v.type];
    switch (v.type) {
      case 0: return this.dv.getUint8(o);
      case 1: return this.dv.getUint8(o) !== 0;
      case 2: return this.dv.getInt32(o, true);
      case 3: return this.dv.getUint32(o, true);
      case 4: return this.dv.getFloat32(o, true);
      default: return this.dv.getFloat64(o, true);
    }
  }
  get(name: string): number | boolean | undefined {
    const v = this.vars.get(name);
    return v ? this.read(v, 0) : undefined;
  }
  num(name: string, fallback = NaN): number {
    const x = this.get(name);
    return x === undefined ? fallback : Number(x);
  }
  array(name: string): (number | boolean)[] {
    const v = this.vars.get(name);
    return v ? Array.from({ length: v.count }, (_, i) => this.read(v, i)) : [];
  }
}

export class IRSDK {
  private vars = new Map<string, VarHeader>();
  private infoUpdate = -1;
  private info: any = null;
  private event: unknown = null;
  private w: Win32;
  private handle: unknown;
  private ptr: unknown;
  private mem: DataView;

  private constructor(w: Win32, handle: unknown, ptr: unknown, mem: DataView) {
    this.w = w; this.handle = handle; this.ptr = ptr; this.mem = mem;
  }

  /** Attach to the sim. Returns null when iRacing isn't running (the mapping doesn't exist). */
  static async open(name = MAP_NAME, eventName: string | null = DATA_EVENT): Promise<IRSDK | null> {
    const w = await win32();
    const h = w.OpenFileMappingW(FILE_MAP_READ, false, name);
    if (!h) return null;
    const head = w.MapViewOfFile(h, FILE_MAP_READ, 0, 0, 0);
    if (!head) { w.CloseHandle(h); return null; }
    // map just the header first, then the full extent the header describes
    const hv = new DataView(w.view(head, HEADER_LEN));
    const i32 = (o: number) => hv.getInt32(o, true);
    const numBuf = Math.min(4, i32(32));
    const bufLen = i32(36);
    let extent = Math.max(HEADER_LEN, i32(20) + i32(16), i32(28) + i32(24) * VAR_HEADER_LEN);
    for (let b = 0; b < numBuf; b++) extent = Math.max(extent, i32(48 + b * 16 + 4) + bufLen);
    const sdk = new IRSDK(w, h, head, new DataView(w.view(head, extent)));
    if (eventName) sdk.event = w.OpenEventW(SYNCHRONIZE, false, eventName);
    sdk.loadVars();
    return sdk;
  }

  private i32(o: number) { return this.mem.getInt32(o, true); }
  get connected() { return (this.i32(4) & STATUS_CONNECTED) !== 0; }
  get sessionInfoUpdate() { return this.i32(12); }

  private loadVars() {
    this.vars.clear();
    const n = this.i32(24), base = this.i32(28);
    const td = new TextDecoder('latin1');
    for (let i = 0; i < n; i++) {
      const o = base + i * VAR_HEADER_LEN;
      const raw = new Uint8Array(this.mem.buffer, this.mem.byteOffset + o + 16, 32);
      const name = td.decode(raw.subarray(0, raw.indexOf(0) < 0 ? 32 : raw.indexOf(0)));
      this.vars.set(name, { type: this.i32(o), offset: this.i32(o + 4), count: this.i32(o + 8), name });
    }
  }

  /** Newest telemetry buffer, copied out and re-checked so it is never half-written. */
  frame(): Frame {
    const numBuf = Math.min(4, this.i32(32)), bufLen = this.i32(36);
    if (this.vars.size !== this.i32(24)) this.loadVars();
    for (let attempt = 0; attempt < 5; attempt++) {
      let best = 0;
      for (let b = 1; b < numBuf; b++) if (this.i32(48 + b * 16) > this.i32(48 + best * 16)) best = b;
      const tick = this.i32(48 + best * 16);
      const off = this.i32(48 + best * 16 + 4);
      const copy = new Uint8Array(this.mem.buffer, this.mem.byteOffset + off, bufLen).slice();
      if (this.i32(48 + best * 16) === tick) return new Frame(this.vars, copy, tick);
    }
    throw new Error('telemetry buffer kept changing while reading');
  }

  /** Parsed session info YAML (re-parsed only when the sim bumps sessionInfoUpdate). */
  sessionInfo(): any {
    const upd = this.sessionInfoUpdate;
    if (upd !== this.infoUpdate || !this.info) {
      const len = this.i32(16), off = this.i32(20);
      const raw = new Uint8Array(this.mem.buffer, this.mem.byteOffset + off, len);
      const end = raw.indexOf(0);
      this.info = parseSessionInfo(new TextDecoder('latin1').decode(raw.subarray(0, end < 0 ? len : end)));
      this.infoUpdate = upd;
    }
    return this.info;
  }

  /** Block until the sim publishes new telemetry (or the timeout passes). */
  waitForData(ms = 100): boolean {
    if (!this.event) return false;
    return this.w.WaitForSingleObject(this.event, ms) === 0;
  }

  close() {
    if (this.event) this.w.CloseHandle(this.event);
    this.w.UnmapViewOfFile(this.ptr);
    this.w.CloseHandle(this.handle);
  }
}

let broadcastMsg = 0;
/** irsdk_broadcastMsg(msg, var1, var2): wParam = MAKELONG(msg, var1), lParam = var2. */
export async function broadcast(msg: number, var1 = 0, var2 = 0): Promise<void> {
  const w = await win32();
  broadcastMsg ||= w.RegisterWindowMessageW('IRSDK_BROADCASTMSG');
  const wParam = ((var1 & 0xffff) << 16 | (msg & 0xffff)) >>> 0;
  w.SendNotifyMessageW(0xffff /* HWND_BROADCAST */, broadcastMsg, wParam, var2 | 0);
}
