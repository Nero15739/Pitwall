/* Display formatting. No stats are computed in the browser; these only shape numbers for reading. */

export const lap = (t: number | null | undefined) => {
  if (t == null) return '–';
  const m = Math.floor(t / 60), s = t - m * 60;
  return `${m}:${s.toFixed(3).padStart(6, '0')}`;
};

/** Signed number with a real minus sign: +0.123 / −0.123 / ±0.000 */
export const sgn = (x: number | null | undefined, d = 3, unit = '') =>
  x == null ? '–' : (x > 0 ? '+' : x < 0 ? '−' : '±') + Math.abs(x).toFixed(d) + unit;

export const pct = (x: number | null | undefined, d = 2) => (x == null ? '–' : `${x.toFixed(d)}%`);
export const fix = (x: number | null | undefined, d = 1) => (x == null || !Number.isFinite(x) ? '–' : Number(x).toFixed(d));
export const ord = (n: number | null | undefined) => (n == null ? '–' : `P${n}`);
export const per10 = (perLap: number | null | undefined, d = 1) => (perLap == null ? '–' : (perLap * 10).toFixed(d));

export const date = (iso: string) =>
  new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
export const dateShort = (iso: string) => new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'short' });

export const carShort = (c: string | null | undefined) =>
  (c ?? '').replace(/\s+GT3.*$/i, '').replace(/\s+\(.*\)$/, '').trim() || (c ?? '');

const STOP = new Set(['circuit', 'circuito', 'de', 'del', 'di', 'the', 'autodromo', 'autódromo', 'international', 'raceway', 'park', 'motorsport', 'speedway']);
/** One word for tight spaces: "Circuit de Barcelona Catalunya" → "Barcelona". */
export const trackTiny = (t: string | null | undefined) =>
  (t ?? '').split(/[\s-]+/).find(w => !STOP.has(w.toLowerCase())) ?? t ?? '';

export const firstName = (n: string) => n.split(' ')[0];

export const km = (m: number | null | undefined) => (m == null ? null : m >= 1000 ? `${(m / 1000).toFixed(2)} km` : `${Math.round(m)} m`);
