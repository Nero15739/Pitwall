// What the replay harvester works out from each sample, ported line for line from the Node tools
// (tools/incidentdetector.ts, tools/splittimer.ts, tools/lapchart.ts) so a harvest from either gives
// the same file. Change both together.
using System;
using System.Collections.Generic;
using System.Linq;

namespace PitWall
{
    static class Js
    {
        /// <summary>JavaScript's Math.round(x * f) / f: halves go up, as the Node harvester rounds.</summary>
        public static double Round(double x, double f)
        {
            double v = x * f, r = Math.Floor(v);
            return (v - r >= 0.5 ? r + 1 : r) / f;
        }
    }

    // ---------------------------------------------------------------- incidents (tools/incidentdetector.ts)
    /// <summary>A car leaving the circuit (off track) or rolling backwards (a spin), as seen in replay telemetry.</summary>
    sealed class DetectedIncident { public int Car; public double T, Pct; public int Lap; public string Kind; public bool Off; }

    sealed class IncidentDetector
    {
        const int ON_TRACK = 3, OFF_TRACK = 0;
        const double REJOIN_S = 1;          // a new off-track only after this long back on the circuit
        const double MERGE_S = 3;           // a spin and an off-track this close together are one incident
        const double SPIN_GAP_S = 5;        // one spin per car per 5 s of rolling backwards
        const double BACKWARDS = 0.0003;    // lap fraction moved backwards between samples that counts as a spin

        sealed class CarState { public double Pct; public int Surf; public double? OnSince; public double LastSpin; public DetectedIncident Last; }
        readonly Dictionary<int, CarState> cars = new Dictionary<int, CarState>();
        public readonly List<DetectedIncident> Incidents = new List<DetectedIncident>();

        /// <summary>Forget everything in flight (call when the replay seeks or time jumps).</summary>
        public void Reset() { cars.Clear(); }

        public void Update(int car, double t, double pct, int surf, bool onPit, int lap)
        {
            CarState c;
            if (!cars.TryGetValue(car, out c) || pct < 0 || surf < 0)
            {
                cars[car] = new CarState { Pct = pct, Surf = surf, OnSince = surf == ON_TRACK ? t : (double?)null, LastSpin = double.NegativeInfinity };
                return;
            }
            // off track: left the circuit after being properly back on it
            if (surf == OFF_TRACK && c.Surf == ON_TRACK && !onPit && c.OnSince != null && t - c.OnSince.Value >= REJOIN_S)
                Record(c, new DetectedIncident { Car = car, T = t, Pct = c.Pct, Lap = lap, Kind = "off", Off = true });
            if (surf != ON_TRACK) c.OnSince = null;
            else if (c.Surf != ON_TRACK) c.OnSince = t;

            // spin: rolling backwards along the lap (a forward line crossing jumps ~-1, so it's excluded)
            double d = pct - c.Pct;
            if (!onPit && (surf == ON_TRACK || surf == OFF_TRACK) && d < -BACKWARDS && d > -0.5)
            {
                if (t - c.LastSpin > SPIN_GAP_S) Record(c, new DetectedIncident { Car = car, T = t, Pct = c.Pct, Lap = lap, Kind = "spin", Off = surf == OFF_TRACK });
                c.LastSpin = t;
            }
            c.Pct = pct;
            c.Surf = surf;
        }

        void Record(CarState c, DetectedIncident e)
        {
            var prev = c.Last;
            if (prev != null && e.T - prev.T <= MERGE_S)
            {
                if (e.Kind == "spin") prev.Kind = "spin";
                prev.Off = prev.Off || e.Off;
                return;
            }
            Incidents.Add(e);
            c.Last = e;
        }
    }

    // ---------------------------------------------------------------- sector times (tools/splittimer.ts)
    sealed class TimedLap { public int Car, Lap; public double Start, LapTime; public double[] Sectors; public bool Pit; }

    /// <summary>Times cars across sector lines from sampled lap distance: interpolates when each line was
    /// crossed and returns every lap that saw all of its lines.</summary>
    sealed class SplitTimer
    {
        sealed class CarState { public double D, T; public int Lap; public Dictionary<double, double> Crossings = new Dictionary<double, double>(); public bool Pit; }
        readonly Dictionary<int, CarState> cars = new Dictionary<int, CarState>();
        readonly double[] sectors;
        readonly double maxGap;

        /// <summary>sectors: line positions as lap distance, ascending, first is 0. maxGap: seconds between samples before we assume a seek.</summary>
        public SplitTimer(double[] sectors, double maxGap = 3) { this.sectors = sectors; this.maxGap = maxGap; }

        public void Forget(int car) { cars.Remove(car); }

        public List<TimedLap> Update(int car, double pct, double t, int lap, bool onPit)
        {
            var outList = new List<TimedLap>();
            CarState c;
            if (!cars.TryGetValue(car, out c) || t - c.T > maxGap || t < c.T)
            {
                cars[car] = new CarState { D = pct, T = t, Lap = lap, Pit = onPit };
                return outList;
            }
            // unwrapped distance: crossing the line takes pct from ~1 back to ~0
            double d = Math.Floor(c.D) + pct;
            if (d < c.D - 0.5) d += 1;
            if (d <= c.D) { c.T = t; return outList; }   // stopped or rolling backwards: keep the furthest point
            c.Pit = c.Pit || onPit;
            for (double n = Math.Floor(c.D); n <= Math.Floor(d); n++)
            {
                for (int k = 0; k < sectors.Length; k++)
                {
                    double b = n + sectors[k];
                    if (b <= c.D || b > d) continue;
                    double at = c.T + ((b - c.D) / (d - c.D)) * (t - c.T);
                    c.Crossings[b] = at;
                    if (k != 0) continue;
                    // crossed the line: the lap that began at b - 1 is complete if every line was seen
                    var marks = new double[sectors.Length];
                    bool all = true;
                    for (int j = 0; j < sectors.Length && all; j++) all = c.Crossings.TryGetValue(b - 1 + sectors[j], out marks[j]);
                    if (all)
                    {
                        var ends = marks.Concat(new[] { at }).ToArray();
                        outList.Add(new TimedLap
                        {
                            Car = car, Lap = c.Lap, Start = Js.Round(marks[0], 1000),
                            Sectors = sectors.Select((_, j) => Js.Round(ends[j + 1] - ends[j], 1000)).ToArray(),
                            LapTime = Js.Round(at - marks[0], 1000),
                            Pit = c.Pit,
                        });
                    }
                    foreach (var key in c.Crossings.Keys.Where(x => x < b).ToList()) c.Crossings.Remove(key);
                    c.Pit = onPit;
                    c.Lap = lap;
                }
            }
            c.D = d;
            c.T = t;
            return outList;
        }
    }

    // ---------------------------------------------------------------- lap chart (tools/lapchart.ts)
    /// <summary>The order the cars completed each race lap. The sim's lap counter says when a lap is done;
    /// lap distance places the crossing between two samples, so close cars keep their order even at 16×.</summary>
    sealed class LapChart
    {
        sealed class CarState { public int Lap = -1; public double Pct = -1, T; public readonly List<double> Done = new List<double>(); }   // Done[n - 1]: when lap n was completed (NaN: not seen)
        readonly Dictionary<int, CarState> cars = new Dictionary<int, CarState>();
        readonly List<int> seen = new List<int>();     // first-seen order breaks ties, as in the Node version

        /// <summary>lap is the sim's CarIdxLap: 0 on the grid, 1 from the green flag, n + 1 once lap n is done, -1 out of the world.</summary>
        public void Update(int car, double t, double pct, int lap)
        {
            CarState c;
            if (!cars.TryGetValue(car, out c)) { cars[car] = c = new CarState { T = t }; seen.Add(car); }
            if (lap > c.Lap && c.Lap >= 1)
            {
                double at = t;
                if (pct >= 0 && c.Pct >= 0 && t > c.T)
                {
                    // distance past the line now and a sample ago; the counter can tick a sample either side of the wrap
                    double x = pct < 0.5 ? pct : pct - 1;
                    double x0 = pct < 0.5 && c.Pct < 0.5 ? c.Pct : c.Pct - 1;
                    double v = (x - x0) / (t - c.T);
                    double guess = v > 0 ? t - x / v : t;
                    if (Math.Abs(guess - t) <= 2 * (t - c.T)) at = guess;
                }
                for (int n = c.Lap; n < lap; n++)
                {
                    while (c.Done.Count < n) c.Done.Add(double.NaN);
                    if (double.IsNaN(c.Done[n - 1])) c.Done[n - 1] = at;
                }
            }
            if (lap > c.Lap) c.Lap = lap;
            c.Pct = pct;
            c.T = t;
        }

        /// <summary>Laps the leader has completed so far.</summary>
        public int Laps { get { return cars.Values.Select(c => c.Done.Count).DefaultIfEmpty(0).Max(); } }

        /// <summary>Cars in the order they completed each lap; [0] is lap 1. A car that never finished a lap isn't in it.</summary>
        public List<List<int>> Order()
        {
            return Enumerable.Range(0, Laps).Select(n => seen
                .Where(car => n < cars[car].Done.Count && !double.IsNaN(cars[car].Done[n]))
                .OrderBy(car => cars[car].Done[n]).ToList()).ToList();
        }
    }
}
