/* Live incidents: turns iRacing's per-driver incident counts into incidents placed on the track.

   The counts live in the session info (DriverInfo.Drivers[].CurDriverIncidentCount, and
   ResultsPositions[].Incidents), which the sim rewrites every few seconds, not every tick. So by
   the time a count goes up the car has moved on. Telemetry (60 Hz) keeps a short history of every
   car's position and its last trip off the circuit; an incident is placed where the car last left
   the track within the lookback, else where it was when the count rose.

   The rise says what happened: +1 off track, +2 spin or wall, +4 car contact. A larger rise is
   several incidents between two updates (or a session's own scale), reported as is. */

export interface Place { t: number; lap: number; pct: number }
export interface LiveIncident {
  t: number;              // session time the rise was seen
  source: string;         // which count it came from ('drivers' or 'results')
  car: number;            // CarIdx
  from: number; to: number; delta: number;
  kind: string;
  at: Place | null;       // where the car was when the rise was seen
  off: Place | null;      // its last off-track within the lookback, if any
}

export const kindOf = (delta: number) =>
  delta === 1 ? 'off track' : delta === 2 ? 'spin / wall' : delta === 4 ? 'car contact' : `${delta}x`;

const OFF_TRACK = 0;          // irsdk_TrkLoc
const HIST_HZ = 4;            // position samples kept per second, per car

interface CarState { hist: Place[]; next: number; lastOff: Place | null; wasOff: boolean }

export class LiveIncidents {
  private cars = new Map<number, CarState>();
  private base = new Map<string, Map<number, number>>(); // source → car → last count seen
  private lookback: number;
  private histLen: number;

  constructor(lookbackS = 10) {
    this.lookback = lookbackS;
    this.histLen = Math.ceil(lookbackS * HIST_HZ) + 1;
  }

  /** Every telemetry read: where each car is and whether it's off the circuit. */
  telemetry(t: number, pct: ArrayLike<number | boolean>, lap: ArrayLike<number | boolean>, surface: ArrayLike<number | boolean>) {
    for (let i = 0; i < pct.length; i++) {
      const p = Number(pct[i]);
      if (!(p >= 0)) continue;                                    // not in the world
      let c = this.cars.get(i);
      if (!c) this.cars.set(i, (c = { hist: [], next: 0, lastOff: null, wasOff: false }));
      const here = { t, lap: Number(lap[i]), pct: p };
      const off = Number(surface[i]) === OFF_TRACK;
      if (off && !c.wasOff) c.lastOff = here;                      // the moment it left the circuit
      c.wasOff = off;
      if (t >= c.next) {
        if (c.hist.length >= this.histLen) c.hist.shift();
        c.hist.push(here);
        c.next = t + 1 / HIST_HZ;
      }
    }
  }

  /** Where a car was at (or just before) time t, from the history. */
  placeAt(car: number, t: number): Place | null {
    const h = this.cars.get(car)?.hist ?? [];
    let best: Place | null = null;
    for (const p of h) if (p.t <= t) best = p;
    return best ?? h[0] ?? null;
  }

  /** A session info update: compare each car's count with the last one seen from this source.
      The first count seen for a car is its baseline; -1 (hidden) never raises an incident. */
  counts(t: number, source: string, counts: Map<number, number>): LiveIncident[] {
    let base = this.base.get(source);
    if (!base) this.base.set(source, (base = new Map()));
    const out: LiveIncident[] = [];
    for (const [car, n] of counts) {
      const prev = base.get(car);
      base.set(car, n);
      if (prev === undefined || prev < 0 || n < 0 || n <= prev) continue;
      const c = this.cars.get(car);
      const off = c?.lastOff && t - c.lastOff.t <= this.lookback ? c.lastOff : null;
      out.push({ t, source, car, from: prev, to: n, delta: n - prev, kind: kindOf(n - prev), at: this.placeAt(car, t), off });
    }
    return out;
  }

  /** A new session (practice → qualifying → race): counts start again. */
  reset() {
    this.base.clear();
    this.cars.clear();
  }
}
