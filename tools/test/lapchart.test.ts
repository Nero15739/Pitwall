import assert from 'node:assert/strict';
import { test } from 'node:test';
import { LapChart } from '../lapchart.ts';

/** Run cars round at constant lap times from a standing start, sampling every `dt` seconds.
    Each car: [grid slot as lap distance, lap time, time it parks (optional)]. */
function race(cars: [number, number, number?][], seconds: number, dt: number, green = 5) {
  const chart = new LapChart();
  for (let t = 0; t <= seconds; t += dt) {
    cars.forEach(([grid, lapTime, parksAt], car) => {
      if (parksAt !== undefined && t >= parksAt) return chart.update(car, t, -1, -1);
      // laps from the line: a slot behind it starts just below zero and its first crossing completes nothing
      const d = (grid > 0.5 ? grid - 1 : grid) + Math.max(0, t - green) / lapTime;
      chart.update(car, t, d - Math.floor(d), t < green ? 0 : Math.max(0, Math.floor(d)) + 1);
    });
  }
  return chart.order();
}

test('orders cars by when they finish each lap, whichever side of the line they gridded', () => {
  // car 0 on pole past the line, car 1 behind the line and quicker: it leads from lap 2
  const order = race([[0.02, 100], [0.99, 98]], 520, 1 / 60);
  assert.deepEqual(order.slice(0, 4), [[0, 1], [1, 0], [1, 0], [1, 0]]);
});

test('the green flag itself is not a lap', () => {
  assert.deepEqual(race([[0.02, 100]], 60, 0.25), []);
});

test('sparse samples (16× replay, ~0.27 s apart) keep cars a tenth apart in the right order', () => {
  // same pace, car 1 gridded 0.001 of a lap (0.1 s) behind car 0 and stays there
  const order = race([[0.020, 100], [0.019, 100]], 420, 16 / 60);
  assert.deepEqual(order, [[0, 1], [0, 1], [0, 1], [0, 1]]);
});

test('a car that parks drops off the chart; the rest keep their order', () => {
  const order = race([[0.02, 100], [0.01, 101, 250], [0.005, 103]], 420, 0.25);
  assert.deepEqual(order, [[0, 1, 2], [0, 1, 2], [0, 2], [0, 2]]);
});

test('a lap credited while the car was out of sight still counts once', () => {
  const chart = new LapChart();
  chart.update(7, 0, 0.5, 1);
  chart.update(7, 10, 0.9, 1);
  chart.update(7, 11, -1, -1);     // towed
  chart.update(7, 40, 0.03, 2);    // back in the pits, past the line, lap counted
  chart.update(7, 41, 0.04, 2);
  assert.deepEqual(chart.order(), [[7]]);
});
