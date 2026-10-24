// Minimal iRacing SDK reader: the sim's shared memory (telemetry + session info). Same layout as
// tools/irsdk.ts. Reads only the variables asked for, straight from the newest buffer, and re-checks
// the buffer's tick afterwards so a frame is never half-written.
using System;
using System.Collections.Generic;
using System.Runtime.InteropServices;
using System.Text;

namespace PitWall
{
    sealed class VarInfo
    {
        public string Name;
        public int Type, Offset, Count;   // type: 0 char, 1 bool, 2 int, 3 bitfield, 4 float, 5 double
    }

    sealed class Sdk : IDisposable
    {
        public const string MapName = @"Local\IRSDKMemMapFileName";
        public const string EventName = @"Local\IRSDKDataValidEvent";
        const uint FILE_MAP_READ = 4, SYNCHRONIZE = 0x00100000;
        const int VAR_HEADER_LEN = 144;

        [DllImport("kernel32", CharSet = CharSet.Unicode)] static extern IntPtr OpenFileMappingW(uint access, bool inherit, string name);
        [DllImport("kernel32")] static extern IntPtr MapViewOfFile(IntPtr h, uint access, uint hi, uint lo, UIntPtr size);
        [DllImport("kernel32")] static extern bool UnmapViewOfFile(IntPtr p);
        [DllImport("kernel32")] static extern bool CloseHandle(IntPtr h);
        [DllImport("kernel32", CharSet = CharSet.Unicode)] static extern IntPtr OpenEventW(uint access, bool inherit, string name);
        [DllImport("kernel32")] static extern uint WaitForSingleObject(IntPtr h, uint ms);

        static readonly Encoding Latin1 = Encoding.GetEncoding(28591);
        IntPtr handle, view, evt;
        readonly Dictionary<string, VarInfo> vars = new Dictionary<string, VarInfo>();
        int varCount = -1;

        /// <summary>Attach to the sim; null when it isn't running (the mapping doesn't exist).</summary>
        public static Sdk Open(string mapName, string eventName)
        {
            IntPtr h = OpenFileMappingW(FILE_MAP_READ, false, mapName);
            if (h == IntPtr.Zero) return null;
            IntPtr v = MapViewOfFile(h, FILE_MAP_READ, 0, 0, UIntPtr.Zero);
            if (v == IntPtr.Zero) { CloseHandle(h); return null; }
            var sdk = new Sdk { handle = h, view = v };
            if (eventName != null) sdk.evt = OpenEventW(SYNCHRONIZE, false, eventName);
            sdk.LoadVars();
            return sdk;
        }

        int I32(int o) { return Marshal.ReadInt32(view, o); }
        public bool Connected { get { return (I32(4) & 1) != 0; } }
        public int TickRate { get { return I32(8); } }
        public int SessionInfoUpdate { get { return I32(12); } }

        void LoadVars()
        {
            vars.Clear();
            int n = I32(24), b = I32(28);
            var name = new byte[32];
            for (int i = 0; i < n; i++)
            {
                int o = b + i * VAR_HEADER_LEN;
                Marshal.Copy(view + o + 16, name, 0, 32);
                int len = Array.IndexOf(name, (byte)0);
                var v = new VarInfo { Name = Latin1.GetString(name, 0, len < 0 ? 32 : len), Type = I32(o), Offset = I32(o + 4), Count = I32(o + 8) };
                vars[v.Name] = v;
            }
            varCount = n;
        }

        /// <summary>A variable's layout, or null if the sim doesn't publish it.</summary>
        public VarInfo Var(string name)
        {
            if (I32(24) != varCount) LoadVars();
            VarInfo v;
            return vars.TryGetValue(name, out v) ? v : null;
        }

        /// <summary>Offset of the newest telemetry buffer and its tick.</summary>
        public int Latest(out int tick)
        {
            int numBuf = Math.Min(4, I32(32)), best = 0;
            for (int b = 1; b < numBuf; b++) if (I32(48 + b * 16) > I32(48 + best * 16)) best = b;
            tick = I32(48 + best * 16);
            return I32(48 + best * 16 + 4);
        }

        /// <summary>Is the buffer at this offset still holding this tick? (False: the sim overwrote it while we read.)</summary>
        public bool StillTick(int buf, int tick)
        {
            int numBuf = Math.Min(4, I32(32));
            for (int b = 0; b < numBuf; b++) if (I32(48 + b * 16 + 4) == buf) return I32(48 + b * 16) == tick;
            return false;
        }

        int At(int buf, VarInfo v, int i) { return buf + v.Offset + i * (v.Type == 5 ? 8 : v.Type <= 1 ? 1 : 4); }
        public double Num(int buf, VarInfo v, int i = 0)
        {
            if (v == null) return double.NaN;
            int o = At(buf, v, i);
            switch (v.Type)
            {
                case 0: case 1: return Marshal.ReadByte(view, o);
                case 2: return Marshal.ReadInt32(view, o);
                case 3: return (uint)Marshal.ReadInt32(view, o);
                case 4: return BitConverter.ToSingle(BitConverter.GetBytes(Marshal.ReadInt32(view, o)), 0);
                default: return BitConverter.Int64BitsToDouble(Marshal.ReadInt64(view, o));
            }
        }
        public int Int(int buf, VarInfo v, int i = 0) { return v == null ? -1 : Marshal.ReadInt32(view, At(buf, v, i)); }
        public float Float(int buf, VarInfo v, int i = 0)
        {
            return v == null ? float.NaN : BitConverter.ToSingle(BitConverter.GetBytes(Marshal.ReadInt32(view, At(buf, v, i))), 0);
        }

        /// <summary>The session info YAML as the sim wrote it (re-read if it changed mid-copy).</summary>
        public string SessionInfoText()
        {
            for (int attempt = 0; ; attempt++)
            {
                int upd = SessionInfoUpdate, len = I32(16), off = I32(20);
                var raw = new byte[len];
                Marshal.Copy(view + off, raw, 0, len);
                int end = Array.IndexOf(raw, (byte)0);
                string text = Latin1.GetString(raw, 0, end < 0 ? len : end);
                if (SessionInfoUpdate == upd || attempt >= 4) return text;
            }
        }

        /// <summary>Sleep until the sim publishes a new tick (or the timeout passes).</summary>
        public bool WaitForData(int ms)
        {
            if (evt == IntPtr.Zero) { System.Threading.Thread.Sleep(Math.Min(ms, 16)); return false; }
            return WaitForSingleObject(evt, (uint)ms) == 0;
        }

        public void Dispose()
        {
            if (evt != IntPtr.Zero) CloseHandle(evt);
            if (view != IntPtr.Zero) UnmapViewOfFile(view);
            if (handle != IntPtr.Zero) CloseHandle(handle);
            evt = view = handle = IntPtr.Zero;
        }
    }
}
