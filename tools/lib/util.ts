/* Small helpers shared by the compiler. Semantics follow the v1 Python build so numbers match. */
import { mkdirSync, renameSync, statSync, writeFileSync } from 'node:fs';
import { dirname } from 'node:path';

/** iRacing times are 1/10000 s; -1 or 0 means no time. */
export const secs = (t: unknown): number | null =>
  typeof t === 'number' && t > 0 ? rnd(t / 10000, 4) : null;

/** Round like Python's round(x, n), returning null for null. */
export function rnd(x: number, n?: number): number;
export function rnd(x: number | null | undefined, n?: number): number | null;
export function rnd(x: number | null | undefined, n = 3): number | null {
  if (x == null || !Number.isFinite(x)) return null;
  return Number(x.toFixed(n));
}

/** Compensated (Neumaier) summation, the same algorithm Python's sum() uses for floats. */
export function sum(xs: number[]): number {
  let s = 0, c = 0;
  for (const x of xs) {
    const t = s + x;
    c += Math.abs(s) >= Math.abs(x) ? (s - t) + x : (x - t) + s;
    s = t;
  }
  return c && Number.isFinite(c) ? s + c : s;
}

export function mean(xs: (number | null | undefined)[]): number | null {
  const v = xs.filter((x): x is number => x != null);
  return v.length ? sum(v) / v.length : null;
}

/** Population standard deviation (statistics.pstdev). */
export function pstdev(xs: number[]): number {
  const m = sum(xs) / xs.length;
  return Math.sqrt(sum(xs.map(x => (x - m) ** 2)) / xs.length);
}

/** First element with the smallest key, like Python's min(xs, key=...). */
export function minBy<T>(xs: readonly T[], key: (x: T) => number): T | undefined {
  let best: T | undefined, bk = Infinity;
  for (const x of xs) { const k = key(x); if (best === undefined || k < bk) { best = x; bk = k; } }
  return best;
}

/** First element with the largest key, like Python's max(xs, key=...). */
export function maxBy<T>(xs: readonly T[], key: (x: T) => number): T | undefined {
  let best: T | undefined, bk = -Infinity;
  for (const x of xs) { const k = key(x); if (best === undefined || k > bk) { best = x; bk = k; } }
  return best;
}

/** Compare tuples element by element (for multi-key sorts). */
export function cmpTuple(a: (number | string)[], b: (number | string)[]): number {
  for (let i = 0; i < a.length; i++) {
    if (a[i] < b[i]) return -1;
    if (a[i] > b[i]) return 1;
  }
  return 0;
}

/** Counter(...).most_common(): count descending, ties in first-seen order. */
export function mostCommon(xs: string[]): Record<string, number> {
  const counts = new Map<string, number>();
  for (const x of xs) counts.set(x, (counts.get(x) ?? 0) + 1);
  return Object.fromEntries([...counts].sort((a, b) => b[1] - a[1]));
}

export const slug = (s: string) =>
  [...s].map(c => (/[\p{L}\p{N}]/u.test(c) ? c.toLowerCase() : '-')).join('').replace(/^-+|-+$/g, '');

/** Write JSON via temp file + rename so the web server never serves a half-written file. */
export function atomicWrite(path: string, data: unknown) {
  mkdirSync(dirname(path), { recursive: true });
  const tmp = `${path}.${process.pid}.tmp`;
  writeFileSync(tmp, JSON.stringify(data));
  renameSync(tmp, path);
}

export function fileSig(path: string) {
  const st = statSync(path);
  return `${st.size}-${Math.floor(st.mtimeMs / 1000)}`;
}
