// Live incidents (port of tools/liveincidents.ts): turns per-driver incident counts from the
// session info (rewritten every second or so) into incidents placed on the track, using a short
// telemetry history of every car. +1 off track, +2 spin / wall, +4 car contact.
using System.Collections.Generic;

namespace PitWall
{
    sealed class Place { public double T; public int Lap; public double Pct; }

    sealed class Incident
    {
        public double T; public int Car; public int From, To, Delta; public string Kind;
        public Place At, Off;           // where it was ~1 s before the count rose (when it happened); its last off-track in the lookback
        public string Name; public long CustId; public string Number; public bool Ai;
        /// <summary>Best guess of where it happened: the off-track if there was one, else where the car was.</summary>
        public Place Where { get { return Off ?? At; } }
    }

    sealed class LiveIncidents
    {
        sealed class Car { public readonly Queue<Place> Hist = new Queue<Place>(); public double Next; public Place LastOff; public bool WasOff; public double Rise = double.NaN; }
        const int OFF_TRACK = 0;     // irsdk_TrkLoc
        const double HIST_HZ = 10;
        // measured live (Spa practice, 2026-10-24): the count rises ~0.25 s after the car leaves the track,
        // and reaches the session info another 0.2-0.9 s later. So other cars are placed 0.8 s back;
        // your own car's count is in telemetry every tick, which times it exactly (MarkRise).
        const double REPORT_LAG = 0.8, COUNT_LAG = 0.25;
        readonly double lookback;
        readonly int histLen;
        readonly Dictionary<int, Car> cars = new Dictionary<int, Car>();
        readonly Dictionary<int, int> baseCounts = new Dictionary<int, int>();

        public LiveIncidents(double lookbackS = 10) { lookback = lookbackS; histLen = (int)(lookbackS * HIST_HZ) + 1; }

        public static string KindOf(int d) { return d == 1 ? "off track" : d == 2 ? "spin / wall" : d == 4 ? "car contact" : d + "x"; }

        public void Telemetry(double t, float[] pct, int[] lap, int[] surface, int n)
        {
            for (int i = 0; i < n; i++)
            {
                if (!(pct[i] >= 0)) continue;
                Car c;
                if (!cars.TryGetValue(i, out c)) cars[i] = c = new Car();
                bool off = surface[i] == OFF_TRACK;
                if (off && !c.WasOff) c.LastOff = new Place { T = t, Lap = lap[i], Pct = pct[i] };
                c.WasOff = off;
                if (t >= c.Next)
                {
                    if (c.Hist.Count >= histLen) c.Hist.Dequeue();
                    c.Hist.Enqueue(new Place { T = t, Lap = lap[i], Pct = pct[i] });
                    c.Next = t + 1 / HIST_HZ;
                }
            }
        }

        /// <summary>The car's count went up in telemetry at t (only the player's car has this).</summary>
        public void MarkRise(int car, double t)
        {
            Car c;
            if (cars.TryGetValue(car, out c)) c.Rise = t;
        }

        Place PlaceAt(int car, double t)
        {
            Car c;
            if (!cars.TryGetValue(car, out c)) return null;
            Place best = null, first = null;
            foreach (var p in c.Hist) { if (first == null) first = p; if (p.T <= t) best = p; }
            return best ?? first;
        }

        /// <summary>A session info update. The first count seen for a car is its baseline; -1 (hidden) never raises one.</summary>
        public List<Incident> Counts(double t, Dictionary<int, int> counts, bool baselineOnly = false)
        {
            var outList = new List<Incident>();
            foreach (var kv in counts)
            {
                int prev;
                bool had = baseCounts.TryGetValue(kv.Key, out prev);
                baseCounts[kv.Key] = kv.Value;
                if (baselineOnly || !had || prev < 0 || kv.Value < 0 || kv.Value <= prev) continue;
                Car c;
                cars.TryGetValue(kv.Key, out c);
                Place off = c != null && c.LastOff != null && t - c.LastOff.T <= lookback ? c.LastOff : null;
                double when = c != null && t - c.Rise <= lookback ? c.Rise - COUNT_LAG : t - REPORT_LAG;
                if (c != null) c.Rise = double.NaN;
                outList.Add(new Incident { T = t, Car = kv.Key, From = prev, To = kv.Value, Delta = kv.Value - prev, Kind = KindOf(kv.Value - prev), At = PlaceAt(kv.Key, when), Off = off });
            }
            return outList;
        }

        public void Reset() { cars.Clear(); baseCounts.Clear(); }
    }
}
