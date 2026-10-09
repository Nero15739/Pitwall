/* Times cars across sector lines from sampled lap-distance positions.
   Feed it (car, lap distance, session time) samples in time order; it interpolates the moment
   each sector line was crossed and returns every lap that saw all of its lines. */

export interface TimedLap { car: number; lap: number; start: number; sectors: number[]; lap_time: number; pit: boolean }

interface CarState { d: number; t: number; lap: number; crossings: Map<number, number>; pit: boolean }

export class SplitTimer {
  private cars = new Map<number, CarState>();
  private sectors: number[];
  private maxGap: number;

  /** sectors: line positions as lap distance, ascending, first is 0. maxGap: seconds between samples before we assume a seek. */
  constructor(sectors: number[], maxGap = 3) {
    this.sectors = sectors;
    this.maxGap = maxGap;
  }

  forget(car: number) { this.cars.delete(car); }

  update(car: number, pct: number, t: number, lap: number, onPit: boolean): TimedLap[] {
    const out: TimedLap[] = [];
    const c = this.cars.get(car);
    if (!c || t - c.t > this.maxGap || t < c.t) {
      this.cars.set(car, { d: pct, t, lap, crossings: new Map(), pit: onPit });
      return out;
    }
    // unwrapped distance: crossing the line takes pct from ~1 back to ~0
    let d = Math.floor(c.d) + pct;
    if (d < c.d - 0.5) d += 1;
    if (d <= c.d) { c.t = t; return out; } // stopped or rolling backwards: keep the furthest point
    c.pit ||= onPit;
    for (let n = Math.floor(c.d); n <= Math.floor(d); n++) {
      for (let k = 0; k < this.sectors.length; k++) {
        const b = n + this.sectors[k];
        if (b <= c.d || b > d) continue;
        const at = c.t + ((b - c.d) / (d - c.d)) * (t - c.t);
        c.crossings.set(b, at);
        if (k !== 0) continue;
        // crossed the line: the lap that began at b - 1 is complete if every line was seen
        const marks = this.sectors.map(s => c.crossings.get(b - 1 + s));
        if (marks.every(m => m !== undefined)) {
          const ends = [...(marks as number[]), at];
          out.push({
            car, lap: c.lap, start: Math.round((marks[0] as number) * 1000) / 1000,
            sectors: this.sectors.map((_, j) => Math.round((ends[j + 1] - ends[j]) * 1000) / 1000),
            lap_time: Math.round((at - (marks[0] as number)) * 1000) / 1000,
            pit: c.pit,
          });
        }
        for (const key of c.crossings.keys()) if (key < b) c.crossings.delete(key);
        c.pit = onPit;
        c.lap = lap;
      }
    }
    c.d = d;
    c.t = t;
    return out;
  }
}
