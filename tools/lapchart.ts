/* Lap chart from sampled telemetry: the order the cars completed each race lap.
   Feed it (car, session time, lap distance, lap number) samples in time order. The sim's own lap
   counter says when a lap is done, so grids that start past the line, tows and resets all count as
   the sim counts them; lap distance places the crossing between two samples, so cars a fraction of
   a second apart keep their order even at 16× (a sample every ¼ s).
   The Pit Wall Logger has a line-for-line C# port (app/PitWallLogger/HarvestParts.cs): change both. */

interface CarState { lap: number; pct: number; t: number; done: number[] } // done[n - 1]: when lap n was completed

export class LapChart {
  private cars = new Map<number, CarState>();

  /** lap is the sim's CarIdxLap: 0 on the grid, 1 from the green flag, n + 1 once lap n is done, -1 out of the world. */
  update(car: number, t: number, pct: number, lap: number) {
    let c = this.cars.get(car);
    if (!c) this.cars.set(car, c = { lap: -1, pct: -1, t, done: [] });
    if (lap > c.lap && c.lap >= 1) {
      let at = t;
      if (pct >= 0 && c.pct >= 0 && t > c.t) {
        // distance past the line now and a sample ago; the counter can tick a sample either side of the wrap
        const x = pct < 0.5 ? pct : pct - 1;
        const x0 = pct < 0.5 && c.pct < 0.5 ? c.pct : c.pct - 1;
        const v = (x - x0) / (t - c.t);
        const guess = v > 0 ? t - x / v : t;
        if (Math.abs(guess - t) <= 2 * (t - c.t)) at = guess;
      }
      for (let n = c.lap; n < lap; n++) c.done[n - 1] ??= at;
    }
    if (lap > c.lap) c.lap = lap;
    c.pct = pct;
    c.t = t;
  }

  /** Cars in the order they completed each lap; [0] is lap 1. A car that never finished a lap isn't in it. */
  order(): number[][] {
    const laps = Math.max(0, ...[...this.cars.values()].map(c => c.done.length));
    return Array.from({ length: laps }, (_, n) =>
      [...this.cars].filter(([, c]) => c.done[n] !== undefined).sort((a, b) => a[1].done[n] - b[1].done[n]).map(([car]) => car));
  }
}
