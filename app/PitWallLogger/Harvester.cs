// Replay harvester (port of tools/harvest.ts). With a race replay open in iRacing, plays the race at
// 16× and, from the same samples, times every car across the track's sector lines, charts the laps
// (who was where at the end of each lap) and spots incidents (leaving the circuit, or rolling
// backwards in a spin). Takes about race length ÷ 16. Saves incidents-<subsession>.json, the same
// file the Node harvester wrote, for the site's /api/v1/incidents.
//
// It never opens a replay: iRacing only loads one from its own app, which signs the launch in. It
// harvests the replay that's open, and puts it back where it was afterwards.
using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Globalization;
using System.IO;
using System.Linq;
using System.Text.Encodings.Web;
using System.Text.Json;
using System.Threading;

namespace PitWall
{
    /// <summary>A harvest that can't go on, with a message for the person running it.</summary>
    sealed class HarvestError : Exception { public HarvestError(string message) : base(message) { } }

    sealed class Harvester
    {
        const int FAST = 16;                 // replay speed (the sim's maximum)
        const int SETTLE_TICKS = 3;          // the replay must hold still this many reads after a jump
        const int JUMP_TIMEOUT_MS = 1500;    // first wait for a jump to land; retries wait longer
        const int JUMP_TRIES = 3;
        const int READY_GRACE_MS = 1500;     // the replay ignores commands for a moment after loading or seeking
        const int MAX_CARS = 64, NOT_IN_WORLD = -1;

        readonly Sdk sdk;
        readonly string msgName;
        public Action<string> Say = s => { }, Detail = s => { }, Progress = s => { };
        public volatile bool Cancel;
        /// <summary>What it was doing, for the message when it fails.</summary>
        public string Step { get; private set; }

        public Harvester(Sdk sdk, string broadcastName) { this.sdk = sdk; msgName = broadcastName; }

        public static string FileFor(string dir, long sub) { return Path.Combine(dir, "incidents-" + sub.ToString(CultureInfo.InvariantCulture) + ".json"); }

        sealed class Driver { public int CarIdx; public long CustId; public string Name = "", Number = ""; public bool Ai; }

        // ---------------------------------------------------------------- reading the replay
        sealed class Sample
        {
            public int Tick, Frame, Session, FramesLeft;
            public double Time, Speed;
            public readonly float[] Pct = new float[MAX_CARS];
            public readonly int[] Lap = new int[MAX_CARS], Surface = new int[MAX_CARS];
            public readonly bool[] Pit = new bool[MAX_CARS];
        }

        /// <summary>The newest telemetry, re-read if the sim overwrote it mid-read. cars: every car's position too.</summary>
        bool Read(Sample s, bool cars)
        {
            VarInfo vFrame = sdk.Var("ReplayFrameNum"), vEnd = sdk.Var("ReplayFrameNumEnd"), vSpeed = sdk.Var("ReplayPlaySpeed");
            VarInfo vSess = sdk.Var("ReplaySessionNum") ?? sdk.Var("SessionNum"), vTime = sdk.Var("ReplaySessionTime") ?? sdk.Var("SessionTime");
            VarInfo vPct = sdk.Var("CarIdxLapDistPct"), vLap = sdk.Var("CarIdxLap"), vSurf = sdk.Var("CarIdxTrackSurface"), vPit = sdk.Var("CarIdxOnPitRoad");
            for (int attempt = 0; attempt < 5; attempt++)
            {
                int tick, buf = sdk.Latest(out tick);
                s.Frame = sdk.Int(buf, vFrame); s.FramesLeft = sdk.Int(buf, vEnd); s.Speed = sdk.Num(buf, vSpeed);
                s.Session = sdk.Int(buf, vSess); s.Time = sdk.Num(buf, vTime);
                if (cars && vPct != null)
                {
                    int n = Math.Min(MAX_CARS, vPct.Count);
                    for (int i = 0; i < n; i++)
                    {
                        s.Pct[i] = sdk.Float(buf, vPct, i); s.Lap[i] = sdk.Int(buf, vLap, i); s.Surface[i] = sdk.Int(buf, vSurf, i);
                        s.Pit[i] = vPit != null && sdk.Num(buf, vPit, i) != 0;
                    }
                }
                if (sdk.StillTick(buf, tick)) { s.Tick = tick; return true; }
            }
            return false;
        }

        /// <summary>Where the replay is right now, for the log and for error messages.</summary>
        public string Where()
        {
            try
            {
                var s = new Sample();
                if (!Read(s, false)) return "unknown";
                return string.Format(CultureInfo.InvariantCulture, "session {0}, {1:0.0} s, frame {2}, {3} frames left, speed {4}, {5}",
                    s.Session, s.Time, s.Frame, s.FramesLeft, s.Speed, sdk.Connected ? "connected" : "sim closed");
            }
            catch { return "unknown (the sim has gone)"; }
        }

        static string Clock(double t)
        {
            return ((int)Math.Floor(t / 60)).ToString(CultureInfo.InvariantCulture) + ":" + (t % 60).ToString("0.0", CultureInfo.InvariantCulture).PadLeft(4, '0');
        }

        void CheckCancel() { if (Cancel) throw new OperationCanceledException(); }
        void Sleep(int ms)
        {
            var sw = Stopwatch.StartNew();
            while (sw.ElapsedMilliseconds < ms) { CheckCancel(); Thread.Sleep((int)Math.Max(1, Math.Min(50, ms - sw.ElapsedMilliseconds))); }
        }
        void Send(int msg, int var1, int var2) { Sdk.Broadcast(msgName, msg, var1, var2); }

        /// <summary>A freshly opened replay ignores commands until it has loaded: wait for that, plus a moment.</summary>
        void WaitUntilReady()
        {
            var t0 = Stopwatch.StartNew();
            var s = new Sample();
            bool ready = false;
            while (!ready && t0.ElapsedMilliseconds < 60000)
            {
                CheckCancel();
                int tick = Read(s, false) ? s.Tick : -1;
                // a newer tick within 250 ms (the data event may have fired before we started waiting, so check the tick itself)
                bool fresh = false;
                for (var w = Stopwatch.StartNew(); !fresh && w.ElapsedMilliseconds < 250; ) { sdk.WaitForData(50); fresh = Read(s, false) && s.Tick != tick; }
                ready = fresh && s.FramesLeft > 0;
                if (!ready) Sleep(250);
            }
            Detail(string.Format(CultureInfo.InvariantCulture, "  replay {0} after {1:0.0} s: {2}", ready ? "ready" : "still not reporting frames", t0.Elapsed.TotalSeconds, Where()));
            Sleep(READY_GRACE_MS);
        }

        /// <summary>Wait until the replay's frame moves off `from` and then holds still for a few ticks.</summary>
        bool Settle(int from, int timeoutMs)
        {
            var t0 = Stopwatch.StartNew();
            var s = new Sample();
            int last = from, still = 0;
            while (t0.ElapsedMilliseconds < timeoutMs)
            {
                CheckCancel();
                if (!sdk.WaitForData(50)) Thread.Sleep(16);
                if (!Read(s, false)) continue;
                if (s.Frame != from && s.Frame == last) { if (++still >= SETTLE_TICKS) return true; } else still = 0;
                last = s.Frame;
            }
            return false;
        }

        /// <summary>Seek to the start of the race. The sim sometimes drops a seek, so confirm and resend.</summary>
        bool SeekRaceStart(int raceNum)
        {
            var s = new Sample();
            for (int attempt = 0; attempt < JUMP_TRIES; attempt++)
            {
                Read(s, false);
                int from = s.Frame;
                Send(Sdk.ReplaySearchSessionTime, raceNum, 0);
                bool landed = Settle(from, JUMP_TIMEOUT_MS * (attempt + 1));
                Sleep(READY_GRACE_MS);
                Detail("  jump to the race start, try " + (attempt + 1) + ": from frame " + from + ", " + (landed ? "landed" : "didn't settle") + ": " + Where());
                if (Read(s, false) && s.Session == raceNum) return true;
            }
            return false;
        }

        /// <summary>Sector lines as lap distance, from the session's SplitTimeInfo (thirds if the track has none).</summary>
        static double[] SectorStarts(Dictionary<string, object> info)
        {
            var s = Yaml.List(info, "SplitTimeInfo", "Sectors").Select(x => Num(Yaml.Str(x, "SectorStartPct")))
                .Where(p => p >= 0 && p < 1).OrderBy(p => p).ToArray();
            return s.Length > 0 && s[0] == 0 ? s : new[] { 0, 1.0 / 3, 2.0 / 3 };
        }
        static double Num(string s)
        {
            double v;
            return double.TryParse(s, NumberStyles.Float, CultureInfo.InvariantCulture, out v) ? v : double.NaN;
        }

        // ---------------------------------------------------------------- the 16× pass
        sealed class Pass
        {
            public readonly List<TimedLap> Laps = new List<TimedLap>();
            public List<List<int>> Order;
            public List<DetectedIncident> Incidents;
            public readonly List<int> FrameOf = new List<int>();
            public readonly List<double[]> Jumps = new List<double[]>();   // { at, seconds }: the replay clock skipping (nothing seen in between)
            public int Samples, Usual, FrameLeft = -1;
            public double StoppedAt = -1, EndedAt = -1;
        }

        /// <summary>Play the race fast and, from the same samples, time sectors, chart the laps and spot incidents.</summary>
        Pass FastPass(int raceNum, double[] sectors, Dictionary<int, Driver> byIdx, int raceLaps)
        {
            var p = new Pass();
            var timer = new SplitTimer(sectors);
            var chart = new LapChart();
            var detector = new IncidentDetector();
            var steps = new Dictionary<int, int>();    // replay frames between samples → how often (16 at 16×)
            var s = new Sample();
            Send(Sdk.ReplaySetPlaySpeed, FAST, 0);

            int lastFrame = -1;
            double lastT = -1;
            var stalled = Stopwatch.StartNew(); var report = Stopwatch.StartNew();
            try
            {
                for (;;)
                {
                    CheckCancel();
                    sdk.WaitForData(100);
                    if (!sdk.Connected) break;
                    if (!Read(s, true)) continue;
                    int fr = s.Frame;
                    if (s.Session != raceNum) break;                                   // past the race
                    if (fr == lastFrame)                                               // end of the replay (or it was stopped)
                    {
                        if (stalled.ElapsedMilliseconds <= 3000) continue;
                        p.FrameLeft = s.FramesLeft; p.StoppedAt = lastT;
                        break;
                    }
                    if (lastFrame >= 0) { int n; steps.TryGetValue(fr - lastFrame, out n); steps[fr - lastFrame] = n + 1; }
                    lastFrame = fr; stalled.Restart(); p.Samples++;
                    double t = s.Time;
                    if (lastT >= 0 && (t < lastT || t - lastT > 3)) detector.Reset();    // a seek or a stale clock
                    if (lastT >= 0 && (t < lastT || t - lastT > 1))
                    {
                        p.Jumps.Add(new[] { t, t - lastT });
                        Detail(string.Format(CultureInfo.InvariantCulture, "    replay clock jumped {0:0.0} s to {1} (frame {2})", t - lastT, Clock(t), fr));
                    }
                    lastT = t;

                    int before = detector.Incidents.Count;
                    foreach (var kv in byIdx)
                    {
                        int idx = kv.Key;
                        if (idx < 0 || idx >= MAX_CARS) continue;
                        double pct = s.Pct[idx];
                        int surf = s.Surface[idx], lap = s.Lap[idx];
                        bool onPit = s.Pit[idx];
                        chart.Update(idx, t, pct >= 0 ? pct : -1, lap);
                        if (!(pct >= 0) || surf == NOT_IN_WORLD) { timer.Forget(idx); detector.Update(idx, t, -1, -1, false, lap); continue; }
                        p.Laps.AddRange(timer.Update(idx, pct, t, lap, onPit));
                        detector.Update(idx, t, pct, surf, onPit, lap);
                    }
                    for (int k = before; k < detector.Incidents.Count; k++)
                    {
                        p.FrameOf.Add(fr);
                        // as it's spotted; a spin moments after an off-track is folded into the same incident later
                        var e = detector.Incidents[k];
                        Driver d;
                        Detail(string.Format(CultureInfo.InvariantCulture, "    {0,7}  lap {1,2}  {2,5:0.0}%  {3}  {4}", Clock(e.T), e.Lap, e.Pct * 100,
                            e.Kind == "spin" ? "spin     " : "off track", byIdx.TryGetValue(e.Car, out d) ? d.Name : "car " + e.Car));
                    }
                    if (report.ElapsedMilliseconds > 1000)
                    {
                        report.Restart();
                        int lead = chart.Laps;
                        Progress((raceLaps > 0 ? "lap " + Math.Min(lead + 1, raceLaps) + " of " + raceLaps + " · " : "") + Clock(t) + " · "
                            + p.Laps.Count + " laps timed · " + detector.Incidents.Count + " incidents");
                    }
                }
            }
            finally
            {
                try { Send(Sdk.ReplaySetPlaySpeed, 0, 0); } catch { }      // never leave the replay running fast
            }
            p.Usual = steps.OrderByDescending(kv => kv.Value).Select(kv => kv.Key).FirstOrDefault();
            p.Order = chart.Order();
            p.Incidents = detector.Incidents;
            p.EndedAt = lastT;
            return p;
        }

        // ---------------------------------------------------------------- harvest
        /// <summary>Harvest the replay that's open into dir. Returns the file, or null when it was already
        /// harvested and force is off. Throws HarvestError (or OperationCanceledException when Cancel is set).</summary>
        public string Run(Dictionary<string, object> info, string dir, bool force)
        {
            Step = "reading what the sim has loaded";
            int homeFrame = -1;
            try
            {
                var wk = Yaml.Map(info, "WeekendInfo");
                long sub = Yaml.Long(wk, "SubSessionID", 0);
                if (sub <= 0) throw new HarvestError("This replay has no subsession ID (a test or offline session), so it can't be matched to a race.");
                string path = FileFor(dir, sub);
                if (File.Exists(path) && !force) { Say("Subsession " + sub + " is already harvested (" + path + ")."); return null; }

                var sessions = Yaml.List(info, "SessionInfo", "Sessions");
                var race = sessions.LastOrDefault(x => Yaml.Str(x, "SessionType").IndexOf("race", StringComparison.OrdinalIgnoreCase) >= 0);
                if (race == null)
                {
                    string had = string.Join(", ", sessions.Select(x => Yaml.Str(x, "SessionType")));
                    throw new HarvestError("This replay has no race session (it has: " + (had.Length > 0 ? had : "none") + ").");
                }
                int raceNum = Yaml.Int(race, "SessionNum", -1);
                Detail("  sim: " + Yaml.Str(wk, "SimMode") + " · sessions " + string.Join(" ", sessions.Select(x => Yaml.Str(x, "SessionNum") + ":" + Yaml.Str(x, "SessionType")))
                    + " · race is " + raceNum + " · " + Where());
                var byIdx = new Dictionary<int, Driver>();
                foreach (var o in Yaml.List(info, "DriverInfo", "Drivers"))
                {
                    if (Yaml.Int(o, "CarIsPaceCar", 0) != 0 || Yaml.Int(o, "IsSpectator", 0) != 0) continue;
                    var d = new Driver { CarIdx = Yaml.Int(o, "CarIdx", -1), CustId = Yaml.Long(o, "UserID", 0), Name = Yaml.Str(o, "UserName"), Ai = Yaml.Int(o, "CarIsAI", 0) == 1, Number = Yaml.Str(o, "CarNumber") };
                    byIdx[d.CarIdx] = d;
                }
                double len = Engine.LengthOf(Yaml.Str(wk, "TrackLength"));
                object lengthM = len > 0 ? (object)Js.Round(len, 1) : null;
                var sectors = SectorStarts(info);
                string track = Yaml.Str(wk, "TrackDisplayName"), cfgName = Yaml.Str(wk, "TrackConfigName").Trim();
                int raceLaps = Yaml.Int(race, "ResultsLapsComplete", 0);

                Say("Harvesting " + track + (cfgName.Length > 0 ? " (" + cfgName + ")" : "") + " · subsession " + sub + " · " + byIdx.Count + " cars");
                Detail("  track " + Yaml.Str(wk, "TrackID") + " · " + (lengthM ?? "?") + " m · sector lines at " + string.Join(", ", sectors.Select(x => x.ToString("0.0000", CultureInfo.InvariantCulture)))
                    + " · " + byIdx.Values.Count(d => !d.Ai) + " drivers + " + byIdx.Values.Count(d => d.Ai) + " AI");
                var t0 = Stopwatch.StartNew();
                Step = "waiting for the replay to finish loading";
                Progress("waiting for the replay to load");
                WaitUntilReady();
                var at = new Sample();
                Read(at, false);
                homeFrame = at.Frame;
                Send(Sdk.ReplaySetPlaySpeed, 0, 0);
                Step = "moving the replay to the start of the race";
                Progress("moving to the start of the race");
                if (!SeekRaceStart(raceNum))
                {
                    Read(at, false);
                    throw new HarvestError("The replay didn't move to the start of the race after " + JUMP_TRIES + " tries (it's in session " + at.Session + ", the race is session " + raceNum + "). "
                        + "Click on the iRacing window once and leave it alone, then harvest again; if jumps keep stalling, turn off Replay spooling in iRacing's replay options.");
                }

                Step = "playing the race at " + FAST + "×";
                Say("  playing the race at " + FAST + "× to time " + sectors.Length + " sectors and spot incidents (about race length ÷ " + FAST + ")…");
                var pass = FastPass(raceNum, sectors, byIdx, raceLaps);
                Step = "checking the pass";
                Detail(string.Format(CultureInfo.InvariantCulture, "  pass: {0} samples in {1:0} s, usually {2} frames apart, ended at {3}{4}", pass.Samples, t0.Elapsed.TotalSeconds, pass.Usual,
                    Clock(Math.Max(0, pass.EndedAt)), pass.FrameLeft >= 0 ? ", replay stopped with " + pass.FrameLeft + " frames left" : ", ran past the race session"));
                if (!sdk.Connected) throw new HarvestError("iRacing closed while the race was playing (at " + Clock(Math.Max(0, pass.EndedAt)) + "). Nothing was saved.");
                // stopped short of the end of the tape: paused, or the sim lost its place
                if (pass.FrameLeft > 600)
                    throw new HarvestError("The replay stopped moving at " + Clock(Math.Max(0, pass.StoppedAt)) + " with " + (pass.FrameLeft / 60) + " s still to play. "
                        + "Was it paused or the speed changed? Nothing was saved: harvest it again and leave iRacing alone until it finishes.");
                var warnings = new List<string>();
                if (pass.Usual != 0 && pass.Usual != FAST) warnings.Add("the replay mostly ran at " + pass.Usual + "×, not " + FAST + "×: was the speed changed while it played?");
                var skips = pass.Jumps.Where(j => j[1] > 0).ToList();
                if (skips.Count > 0)
                {
                    var worst = skips.Aggregate((a, b) => b[1] > a[1] ? b : a);
                    warnings.Add(string.Format(CultureInfo.InvariantCulture, "the replay skipped ahead {0} time(s), {1:0} s in all (longest {2:0.0} s, to {3}): ", skips.Count, skips.Sum(j => j[1]), worst[1], Clock(worst[0]))
                        + "laps and incidents in those stretches are missing. If it keeps happening, turn off Replay spooling in iRacing's replay options.");
                }
                if (pass.Jumps.Any(j => j[1] < 0)) warnings.Add("the replay clock went backwards during the pass: was it rewound?");
                if (raceLaps > 0 && pass.Order.Count < raceLaps) warnings.Add("the lap chart has " + pass.Order.Count + " of the race's " + raceLaps + " laps: the replay may not cover the whole race.");
                if (pass.Order.Count == 0) warnings.Add("no laps were charted, so the lap-chart awards will skip this round.");

                Step = "saving the result";
                var incidents = pass.Incidents.Select((e, k) =>
                {
                    var d = byIdx[e.Car];
                    return new Dictionary<string, object> {
                        { "frame", pass.FrameOf[k] }, { "t", Js.Round(e.T, 100) }, { "lap", e.Lap }, { "pct", Js.Round(e.Pct, 1e5) },
                        { "car_idx", e.Car }, { "cust_id", d.CustId }, { "name", d.Name }, { "ai", d.Ai }, { "off_track", e.Off }, { "kind", e.Kind } };
                }).ToList();
                var file = new Dictionary<string, object>
                {
                    { "version", 1 },
                    { "subsession", sub },
                    { "track", new Dictionary<string, object> { { "id", Yaml.Int(wk, "TrackID", 0) }, { "name", track }, { "config", cfgName }, { "length_m", lengthM } } },
                    { "harvested_at", DateTime.UtcNow.ToString("yyyy-MM-dd'T'HH:mm:ss.fff'Z'", CultureInfo.InvariantCulture) },
                    { "method", "telemetry" },
                    { "session", new Dictionary<string, object> { { "num", raceNum }, { "type", Yaml.Str(race, "SessionType") } } },
                    { "drivers", byIdx.Values.Select(d => new Dictionary<string, object> { { "car_idx", d.CarIdx }, { "cust_id", d.CustId }, { "name", d.Name }, { "ai", d.Ai }, { "number", d.Number } }).ToList() },
                    { "incidents", incidents },
                    { "lap_order", pass.Order },
                    { "splits", new Dictionary<string, object> {
                        { "sectors", sectors },
                        { "laps", pass.Laps.Select(l => new Dictionary<string, object> {
                            { "car_idx", l.Car }, { "cust_id", byIdx[l.Car].CustId }, { "lap", l.Lap }, { "start", l.Start }, { "sectors", l.Sectors }, { "lap_time", l.LapTime }, { "pit", l.Pit } }).ToList() } } },
                };
                Directory.CreateDirectory(dir);
                string tmp = path + ".tmp";
                File.WriteAllText(tmp, JsonSerializer.Serialize(file, new JsonSerializerOptions { Encoder = JavaScriptEncoder.UnsafeRelaxedJsonEscaping }));
                File.Move(tmp, path, true);
                Send(Sdk.ReplaySetPlayPosition, 0, homeFrame);       // back to where you were
                homeFrame = -1;

                var humans = pass.Incidents.Where(e => !byIdx[e.Car].Ai).ToList();
                var top = humans.GroupBy(e => byIdx[e.Car].Name).Select(g => new { g.Key, N = g.Count() })
                    .OrderByDescending(x => x.N).Take(6).Select(x => x.Key + " " + x.N).ToList();
                Say(string.Format(CultureInfo.InvariantCulture, "Done in {0:0} s: {1} laps timed, {2} laps charted, {3} incidents ({4} by human drivers)",
                    t0.Elapsed.TotalSeconds, pass.Laps.Count, pass.Order.Count, incidents.Count, humans.Count));
                if (top.Count > 0) Say("  " + string.Join(" · ", top));
                foreach (var w in warnings) Say("  Warning: " + w);
                Say("  Saved " + path);
                return path;
            }
            catch
            {
                // put the replay back where it was
                if (homeFrame >= 0) try { Send(Sdk.ReplaySetPlayPosition, 0, homeFrame); } catch { }
                throw;
            }
        }
    }
}
