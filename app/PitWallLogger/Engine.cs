// The logger: waits for the sim (one cheap check every few seconds), and during a live session
// reads telemetry every tick (or at the chosen rate) and the session info whenever the sim rewrites
// it. Incident counts going up become placed incidents in a live log under data/incidents/live/.
// With a replay open instead, it harvests the race on request (Harvester) into data/incidents/.
using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Globalization;
using System.IO;
using System.Linq;
using System.Threading;
using System.Text.Json;

namespace PitWall
{
    enum ViewKind { None, Everyone, Admin, NotAdmin, Checking }

    sealed class EngineState
    {
        public string Status = "Starting";
        public bool Sim, Live, Paused;
        public string Track = "", SessionType = "", Me = "";
        public long Sub;
        public int Cars, Incidents;
        public ViewKind View;
        public string ViewText = "";
        public string LastIncident = "";
        public double TickHz, InfoGapS, ParseMs;
        public string LiveFile;
        public ReplayInfo Replay;               // the replay that's open, if one is
        public bool Harvesting;
        public string HarvestProgress = "";
        public EngineState Clone() { return (EngineState)MemberwiseClone(); }
    }

    /// <summary>A replay open in the sim.</summary>
    sealed class ReplayInfo
    {
        public long Sub; public string Track = ""; public bool HasRace, Harvested; public int RaceLaps;
        public ReplayInfo With(bool harvested) { var r = (ReplayInfo)MemberwiseClone(); r.Harvested = harvested; return r; }
    }

    sealed class DriverRow { public int CarIdx; public long CustId; public string Name = "", Number = ""; public bool Ai; public int Count = -1; }

    sealed class Recording
    {
        public long Sub; public int SessionNum; public string SessionType = "", Track = "", TrackConfig = "", RecordedBy = "", View = "";
        public int TrackId; public long RecordedById;
        public string TrackShort = ""; public double LengthM;
        public DateTime Started = DateTime.UtcNow, Ended;
        public bool Finished;
        public readonly List<Incident> Incidents = new List<Incident>();
        public Dictionary<int, DriverRow> Drivers = new Dictionary<int, DriverRow>();
        public bool IsRace { get { return SessionType.IndexOf("race", StringComparison.OrdinalIgnoreCase) >= 0; } }

        public string FileName
        {
            get
            {
                string type = new string(SessionType.ToLowerInvariant().Where(char.IsLetterOrDigit).ToArray());
                string id = Sub > 0 ? Sub.ToString(CultureInfo.InvariantCulture) : Started.ToLocalTime().ToString("yyyyMMdd-HHmm", CultureInfo.InvariantCulture);
                return "live-" + id + "-s" + SessionNum + "-" + (type.Length > 0 ? type : "session") + ".json";
            }
        }

        static double R(double x, int n) { return Math.Round(x, n); }
        static object P(Place p) { return p == null ? null : new Dictionary<string, object> { { "t", R(p.T, 2) }, { "lap", p.Lap }, { "pct", R(p.Pct, 5) } }; }

        public string Save(string dir)
        {
            Directory.CreateDirectory(dir);
            var doc = new Dictionary<string, object>
            {
                { "version", 1 }, { "method", "live" }, { "subsession", Sub },
                { "session", new Dictionary<string, object> { { "num", SessionNum }, { "type", SessionType } } },
                { "track", new Dictionary<string, object> { { "id", TrackId }, { "name", Track }, { "short", TrackShort }, { "config", TrackConfig }, { "length_m", LengthM > 0 ? (object)Math.Round(LengthM) : null } } },
                { "recorded_by", new Dictionary<string, object> { { "cust_id", RecordedById }, { "name", RecordedBy } } },
                { "view", View },
                { "started", Started.ToString("o") }, { "ended", Ended == default(DateTime) ? null : Ended.ToString("o") },
                { "finished", Finished },
                { "drivers", Drivers.Values.OrderBy(d => d.CarIdx).Select(d => new Dictionary<string, object> {
                    { "car_idx", d.CarIdx }, { "cust_id", d.CustId }, { "name", d.Name }, { "number", d.Number }, { "ai", d.Ai }, { "incident_count", d.Count } }).ToList() },
                { "incidents", Incidents.Select(e => new Dictionary<string, object> {
                    { "t", R(e.T, 2) }, { "car_idx", e.Car }, { "cust_id", e.CustId }, { "name", e.Name }, { "ai", e.Ai },
                    { "from", e.From }, { "to", e.To }, { "delta", e.Delta }, { "kind", e.Kind },
                    { "lap", e.Where == null ? (object)null : e.Where.Lap }, { "pct", e.Where == null ? (object)null : R(e.Where.Pct, 5) },
                    { "seen_at", P(e.At) }, { "off_track_at", P(e.Off) } }).ToList() },
            };
            string path = Path.Combine(dir, FileName), tmp = path + ".tmp";
            File.WriteAllText(tmp, JsonSerializer.Serialize(doc));
            if (File.Exists(path)) File.Delete(path);
            File.Move(tmp, path);
            return path;
        }
    }

    sealed class Engine
    {
        public string MapName = Sdk.MapName, EventName = Sdk.EventName, BroadcastName = Sdk.BroadcastName;
        public string OutDir;                           // live logs
        public string HarvestDir;                       // harvested replays
        public volatile int Hz = 60;
        public volatile bool Paused;
        public bool ExitWhenSimCloses;
        public bool HarvestReplays;                     // harvest the race replay that's open (unless harvested already), then stop (testing)
        public event Action<string> OnLog;
        public event Action<string> OnDetail;           // step-by-step detail, for the log file only
        public event Action<Incident> OnIncident;
        public event Action<string, bool> OnSaved;     // file, race finished
        public event Action<ReplayInfo> OnReplay;       // a replay was opened
        public event Action<string> OnHarvested;        // file

        const int MAX_CARS = 64, SESSION_CHECKERED = 5, STALE_MS = 15000, IDLE_CHECK_MS = 3000;
        // after connecting or a new session, counts are only taken as the starting point for this long
        // (seen live: the first session info can list a driver at 0 and the next at their real total)
        const int SETTLE_MS = 4000;
        readonly object gate = new object();
        EngineState state = new EngineState();
        Thread thread;
        volatile bool stopping, harvestWanted, harvestForce;
        volatile Harvester harvester;
        readonly AutoResetEvent wake = new AutoResetEvent(false);

        public EngineState State { get { lock (gate) return state; } }
        void Set(Action<EngineState> f) { lock (gate) { var s = state.Clone(); f(s); state = s; } }
        void Log(string s) { var h = OnLog; if (h != null) h(s); }
        void Detail(string s) { var h = OnDetail; if (h != null) h(s); }

        public void Start()
        {
            thread = new Thread(Run) { IsBackground = true, Name = "iRacing reader", Priority = ThreadPriority.BelowNormal };
            thread.Start();
        }
        public void Stop() { stopping = true; StopHarvest(); wake.Set(); if (thread != null) thread.Join(3000); }
        public void Join() { if (thread != null) thread.Join(); }
        public void Poke() { wake.Set(); }

        /// <summary>Harvest the replay that's open, if it's still that subsession (force: even if it's been harvested before).</summary>
        public void Harvest(long sub, bool force) { harvestSub = sub; harvestForce = force; harvestWanted = true; wake.Set(); }
        long harvestSub;
        /// <summary>Stop a harvest: nothing is saved and the replay goes back where it was.</summary>
        public void StopHarvest() { harvestWanted = false; var h = harvester; if (h != null) h.Cancel = true; }

        void Run()
        {
            bool told = false;
            while (!stopping)
            {
                Sdk sdk = null;
                try { sdk = Sdk.Open(MapName, EventName); } catch (Exception e) { Log("Couldn't attach to iRacing: " + e.Message); }
                if (sdk != null && sdk.Connected)
                {
                    told = false;
                    try { Session(sdk); }
                    catch (Exception e) { Log("Error: " + e); }
                    finally { sdk.Dispose(); harvestWanted = false; }
                    Set(s => { s.Sim = s.Live = false; s.Status = "Waiting for iRacing"; s.View = ViewKind.None; s.ViewText = ""; s.TickHz = 0; s.Replay = null; s.Harvesting = false; });
                    if (stopping) return;
                    Log("iRacing closed.");
                    if (ExitWhenSimCloses) return;
                    continue;
                }
                if (sdk != null) sdk.Dispose();
                if (!told) { Log("Waiting for iRacing…"); told = true; }
                Set(s => { s.Status = "Waiting for iRacing"; s.Sim = false; s.Paused = Paused; });
                wake.WaitOne(IDLE_CHECK_MS);
            }
        }

        void Session(Sdk sdk)
        {
            Log("Connected to iRacing (telemetry " + sdk.TickRate + "/s).");
            var watch = new LiveIncidents();
            var drivers = new Dictionary<int, DriverRow>();
            var pct = new float[MAX_CARS]; var lap = new int[MAX_CARS]; var surf = new int[MAX_CARS];
            Recording rec = null;
            ReplayInfo replay = null;
            Dictionary<string, object> info = null;
            int lastUpd = -1, lastTick = -1, sessionNum = -1, myIdx = -1, isAdmin = -1;
            var sessionClock = Stopwatch.StartNew();
            string simMode = "";
            bool infoNew = false, adminChecked = false, everNonzero = false;
            var tickClock = Stopwatch.StartNew(); var lastTickAt = Stopwatch.StartNew(); var infoClock = new Stopwatch(); var uiClock = Stopwatch.StartNew();
            int ticks = 0, ownCount = -1; double tickHz = 0, infoGap = 0, parseMs = 0;
            Set(s => { s.Sim = true; s.Status = "Connected"; });

            while (!stopping)
            {
                int hz = Paused ? 2 : Hz;
                if (hz >= 60) sdk.WaitForData(200); else wake.WaitOne(1000 / Math.Max(1, hz));
                if (!sdk.Connected) break;

                int tick, buf = sdk.Latest(out tick);
                if (tick == lastTick)
                {
                    if (lastTickAt.ElapsedMilliseconds > STALE_MS) { Log("No telemetry for 15 s; treating iRacing as closed."); break; }
                    continue;
                }

                // session info: only when the sim has rewritten it
                int upd = sdk.SessionInfoUpdate;
                if (upd != lastUpd)
                {
                    var t0 = Stopwatch.StartNew();
                    string text = sdk.SessionInfoText();
                    info = Yaml.Parse(text);
                    parseMs = t0.Elapsed.TotalMilliseconds;
                    if (infoClock.IsRunning) infoGap = infoClock.Elapsed.TotalSeconds;
                    infoClock.Restart();
                    lastUpd = upd; infoNew = true;
                    simMode = Yaml.Str(info, "WeekendInfo", "SimMode");
                    myIdx = Yaml.Int(Yaml.Map(info, "DriverInfo"), "DriverCarIdx", -1);
                    isAdmin = Yaml.Int(Yaml.Map(info, "DriverInfo"), "DriverIsAdmin", -1);
                    ReadDrivers(info, drivers);
                    if (!adminChecked)
                    {
                        adminChecked = true;
                        var hints = text.Split('\n').Where(l => l.IndexOf("admin", StringComparison.OrdinalIgnoreCase) >= 0).Select(l => l.Trim()).Distinct().Take(10).ToList();
                        Log(hints.Count > 0 ? "Session info mentions admin: " + string.Join(" | ", hints) : "Session info has no field mentioning admin.");
                    }
                }
                if (string.Equals(simMode, "replay", StringComparison.OrdinalIgnoreCase))
                {
                    // a replay: nothing to log; harvest it when asked
                    lastTick = tick; lastTickAt.Restart();
                    long sub = Yaml.Long(Yaml.Map(info, "WeekendInfo"), "SubSessionID", 0);
                    if (replay == null || replay.Sub != sub)
                    {
                        replay = DescribeReplay(info);
                        Log("Replay open: " + replay.Track + (sub > 0 ? ", subsession " + sub : "") + (!replay.HasRace ? " (no race in it)." : replay.Harvested ? " (already harvested)." : "."));
                        var h = OnReplay; if (h != null) h(replay);
                        if (HarvestReplays && replay.HasRace && !replay.Harvested) Harvest(sub, false);
                    }
                    if (harvestWanted)
                    {
                        harvestWanted = false;
                        if (harvestSub == replay.Sub)
                        {
                            RunHarvest(sdk, info, harvestForce);
                            replay = replay.With(File.Exists(Harvester.FileFor(HarvestDir, replay.Sub)));
                            lastTickAt.Restart();
                        }
                    }
                    var rp = replay;
                    Set(s => { s.Live = false; s.Paused = Paused; s.Status = "Replay open"; s.Track = rp.Track; s.SessionType = ""; s.Sub = rp.Sub; s.Replay = rp;
                        s.View = ViewKind.None; s.ViewText = ""; s.TickHz = 0; });
                    if (!harvestWanted) wake.WaitOne(2000);
                    continue;
                }
                if (replay != null) { replay = null; Set(s => s.Replay = null); }
                if (Paused)
                {
                    if (rec != null) { Finish(rec, false); rec = null; sessionNum = -1; }
                    Set(s => { s.Paused = true; s.Status = "Paused"; s.Live = false; });
                    lastTick = tick; lastTickAt.Restart();
                    continue;
                }

                // telemetry: only the variables we need, straight from the newest buffer
                VarInfo vT = sdk.Var("SessionTime"), vSN = sdk.Var("SessionNum"), vSS = sdk.Var("SessionState");
                VarInfo vPct = sdk.Var("CarIdxLapDistPct"), vLap = sdk.Var("CarIdxLap"), vSurf = sdk.Var("CarIdxTrackSurface");
                if (vT == null || vPct == null) continue;
                int n = Math.Min(MAX_CARS, vPct.Count);
                double t = sdk.Num(buf, vT);
                int sn = sdk.Int(buf, vSN), ss = sdk.Int(buf, vSS);
                for (int i = 0; i < n; i++) { pct[i] = sdk.Float(buf, vPct, i); lap[i] = sdk.Int(buf, vLap, i); surf[i] = sdk.Int(buf, vSurf, i); }
                if (!sdk.StillTick(buf, tick)) continue;      // overwritten while reading: take the next one
                lastTick = tick; lastTickAt.Restart(); ticks++;
                if (tickClock.ElapsedMilliseconds >= 2000) { tickHz = ticks * 1000.0 / tickClock.ElapsedMilliseconds; ticks = 0; tickClock.Restart(); }

                if (sn != sessionNum)
                {
                    if (rec != null) Finish(rec, false);
                    sessionNum = sn; watch.Reset(); everNonzero = false; infoNew = true; sessionClock.Restart();
                    rec = NewRecording(info, sn, myIdx, drivers);
                    Log("Session " + sn + ": " + rec.SessionType + " at " + rec.Track + " (" + drivers.Count + " cars).");
                }
                watch.Telemetry(t, pct, lap, surf, n);
                // your own count is in telemetry every tick: it times your incidents exactly
                int own = sdk.Int(buf, sdk.Var("PlayerCarMyIncidentCount"));
                if (ownCount >= 0 && own > ownCount && myIdx >= 0) watch.MarkRise(myIdx, t);
                ownCount = own;

                if (infoNew && rec != null)
                {
                    infoNew = false;
                    rec.Drivers = drivers.ToDictionary(kv => kv.Key, kv => kv.Value);
                    var counts = drivers.Values.ToDictionary(d => d.CarIdx, d => d.Count);
                    foreach (var e in watch.Counts(t, counts, sessionClock.ElapsedMilliseconds < SETTLE_MS))
                    {
                        DriverRow d;
                        if (drivers.TryGetValue(e.Car, out d)) { e.Name = d.Name; e.CustId = d.CustId; e.Number = d.Number; e.Ai = d.Ai; }
                        rec.Incidents.Add(e);
                        var h = OnIncident; if (h != null) h(e);
                    }
                    if (drivers.Values.Any(d => d.CarIdx != myIdx && d.Count > 0)) everNonzero = true;
                    ViewKind view; string viewText;
                    Verdict(rec, drivers, myIdx, isAdmin, everNonzero, out view, out viewText);
                    rec.View = view.ToString();
                    if (rec.Incidents.Count > 0) rec.Save(OutDir);
                    var r = rec;
                    Set(s => { s.View = view; s.ViewText = viewText; s.Incidents = r.Incidents.Count; s.Cars = drivers.Count; s.LiveFile = r.Incidents.Count > 0 ? Path.Combine(OutDir, r.FileName) : null;
                        if (r.Incidents.Count > 0) { var e = r.Incidents[r.Incidents.Count - 1]; s.LastIncident = (e.Number.Length > 0 ? "#" + e.Number + " " : "") + e.Name + ", " + e.Kind; } });
                }

                if (rec != null && rec.IsRace && !rec.Finished && ss >= SESSION_CHECKERED)
                {
                    rec.Finished = true;
                    Log("Race finished (chequered flag).");
                    Finish(rec, true);
                }

                if (uiClock.ElapsedMilliseconds >= 500)
                {
                    uiClock.Restart();
                    var r = rec; double hzNow = tickHz, gap = infoGap, pm = parseMs;
                    Set(s => { s.Live = true; s.Paused = false; s.Status = r != null && r.IsRace ? (r.Finished ? "Race finished" : "Logging race") : "Logging"; s.TickHz = hzNow; s.InfoGapS = gap; s.ParseMs = pm;
                        if (r != null) { s.Track = r.Track; s.SessionType = r.SessionType; s.Sub = r.Sub; s.Me = r.RecordedBy; } });
                }
            }
            if (rec != null && !rec.Finished) Finish(rec, false);
        }

        void Finish(Recording rec, bool raceFinished)
        {
            rec.Ended = DateTime.UtcNow;
            if (rec.Incidents.Count == 0 && !rec.IsRace) return;
            try
            {
                string path = rec.Save(OutDir);
                Log("Saved " + Path.GetFileName(path) + " (" + rec.Incidents.Count + " incidents).");
                var h = OnSaved; if (h != null) h(path, raceFinished);
            }
            catch (Exception e) { Log("Couldn't save the live log: " + e.Message); }
        }

        ReplayInfo DescribeReplay(Dictionary<string, object> info)
        {
            var wk = Yaml.Map(info, "WeekendInfo");
            long sub = Yaml.Long(wk, "SubSessionID", 0);
            var race = Yaml.List(info, "SessionInfo", "Sessions").LastOrDefault(x => Yaml.Str(x, "SessionType").IndexOf("race", StringComparison.OrdinalIgnoreCase) >= 0);
            return new ReplayInfo
            {
                Sub = sub, Track = Yaml.Str(wk, "TrackDisplayName"), HasRace = race != null, RaceLaps = race == null ? 0 : Yaml.Int(race, "ResultsLapsComplete", 0),
                Harvested = sub > 0 && File.Exists(Harvester.FileFor(HarvestDir, sub)),
            };
        }

        void RunHarvest(Sdk sdk, Dictionary<string, object> info, bool force)
        {
            var h = new Harvester(sdk, BroadcastName) { Say = Log, Detail = Detail, Progress = p => Set(s => s.HarvestProgress = p) };
            harvester = h;
            Set(s => { s.Harvesting = true; s.Status = "Harvesting replay"; s.HarvestProgress = "starting"; });
            try
            {
                string file = h.Run(info, HarvestDir, force);
                var done = OnHarvested;
                if (file != null && done != null) done(file);
            }
            catch (OperationCanceledException) { Log("Harvest stopped. Nothing was saved, and the replay is back where it was."); }
            catch (Exception e)
            {
                Log("Harvest failed while " + h.Step + ": " + e.Message);
                Detail("  " + (e is HarvestError ? "" : e + " · ") + "the replay was at: " + h.Where());
            }
            finally
            {
                harvester = null;
                Set(s => { s.Harvesting = false; s.HarvestProgress = ""; });
                if (HarvestReplays) stopping = true;
            }
        }

        static void ReadDrivers(Dictionary<string, object> info, Dictionary<int, DriverRow> into)
        {
            into.Clear();
            foreach (var o in Yaml.List(info, "DriverInfo", "Drivers"))
            {
                if (Yaml.Int(o, "CarIsPaceCar", 0) == 1 || Yaml.Int(o, "IsSpectator", 0) == 1) continue;
                int idx = Yaml.Int(o, "CarIdx", -1);
                if (idx < 0) continue;
                int count = Yaml.Int(o, "CurDriverIncidentCount", int.MinValue);
                if (count == int.MinValue) count = Yaml.Int(o, "TeamIncidentCount", -1);
                into[idx] = new DriverRow { CarIdx = idx, CustId = Yaml.Long(o, "UserID", 0), Name = Yaml.Str(o, "UserName"), Number = Yaml.Str(o, "CarNumber"), Ai = Yaml.Int(o, "CarIsAI", 0) == 1, Count = count };
            }
        }

        static Recording NewRecording(Dictionary<string, object> info, int sn, int myIdx, Dictionary<int, DriverRow> drivers)
        {
            var wk = Yaml.Map(info, "WeekendInfo");
            var sess = Yaml.List(info, "SessionInfo", "Sessions").FirstOrDefault(o => Yaml.Int(o, "SessionNum", -1) == sn);
            DriverRow me;
            drivers.TryGetValue(myIdx, out me);
            return new Recording
            {
                Sub = Yaml.Long(wk, "SubSessionID", 0), SessionNum = sn, SessionType = sess == null ? "?" : Yaml.Str(sess, "SessionType"),
                Track = Yaml.Str(wk, "TrackDisplayName"), TrackShort = Yaml.Str(wk, "TrackDisplayShortName"), TrackConfig = Yaml.Str(wk, "TrackConfigName"), TrackId = Yaml.Int(wk, "TrackID", 0),
                LengthM = LengthOf(Yaml.Str(wk, "TrackLength")),
                RecordedBy = me == null ? "" : me.Name, RecordedById = me == null ? 0 : me.CustId,
            };
        }

        /// <summary>"6.9293 km" or "4.31 mi" → metres (0 when missing).</summary>
        public static double LengthOf(string s)
        {
            var m = System.Text.RegularExpressions.Regex.Match(s, @"([\d.]+)\s*(km|mi)", System.Text.RegularExpressions.RegexOptions.IgnoreCase);
            double v;
            if (!m.Success || !double.TryParse(m.Groups[1].Value, NumberStyles.Float, CultureInfo.InvariantCulture, out v)) return 0;
            return v * (m.Groups[2].Value.ToLowerInvariant() == "mi" ? 1609.344 : 1000);
        }

        /// <summary>Can this PC see everyone's incidents? Outside a race everyone can; in a race only admins should.</summary>
        static void Verdict(Recording rec, Dictionary<int, DriverRow> drivers, int myIdx, int isAdmin, bool everNonzero, out ViewKind view, out string text)
        {
            // the sim says so directly: DriverInfo.DriverIsAdmin
            if (isAdmin == 1) { view = ViewKind.Admin; text = "You're a session admin: logging all drivers"; return; }
            if (isAdmin == 0)
            {
                if (rec.IsRace) { view = ViewKind.NotAdmin; text = "Not a session admin: in a race only your own car is visible"; return; }
                view = ViewKind.Everyone; text = rec.SessionType + ": not admin, but everyone's counts are public here"; return;
            }
            var others = drivers.Values.Where(d => d.CarIdx != myIdx).ToList();
            if (others.Count == 0) { view = ViewKind.Checking; text = "No other drivers yet"; return; }
            if (others.All(d => d.Count < 0)) { view = ViewKind.NotAdmin; text = "Other drivers' counts are hidden: own car only"; return; }
            if (!rec.IsRace) { view = ViewKind.Everyone; text = rec.SessionType + ": everyone's counts are public, logging all drivers"; return; }
            if (everNonzero) { view = ViewKind.Admin; text = "Admin view: logging all drivers"; return; }
            view = ViewKind.Checking; text = "Race: others all at 0 so far (admin view not confirmed yet)";
        }
    }
}
