/* The season calendar: rounds already raced plus the rounds still to come from a schedule file
   (data/<season>/schedule.json). A scheduled round counts as raced once a result at that track,
   within three weeks of its date, exists. */
import type { CalendarEntry, Round, ScheduleEntry } from '../lib/types.ts';
import type { Race } from './race.ts';

export const MAX_ROUNDS = 60;
/** A result this far from its scheduled date is a different visit to the same track. */
const MATCH_WINDOW_S = 21 * 86400;
const WHEN = /^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2}))?(?:\.\d+)?(Z|[+-]\d{2}:?\d{2}))?$/;

/** Letters and digits only, lower case: how track names are compared. */
export const trackKey = (s: string) => s.replace(/[^\p{L}\p{N}]/gu, '').toLowerCase();

const text = (v: unknown, max = 200): string | null => {
  if (typeof v !== 'string') return null;
  const s = v.trim();
  return s === '' ? null : [...s].slice(0, max).join('');
};

/** A whole number above zero (JSON may carry it as 12, 12.0 or "12"), else null. */
const whole = (v: unknown): number | null => {
  const ok = (typeof v === 'number' && Number.isInteger(v)) || (typeof v === 'string' && /^\d+$/.test(v));
  return ok && Number(v) > 0 ? Number(v) : null;
};

const pad = (n: number, w = 2) => String(n).padStart(w, '0');

/** "2026-11-02" stays a date; "2026-11-02T19:30:00+11:00" becomes UTC. Null when it isn't either. */
export function when(s: string): string | null {
  const m = WHEN.exec(s.trim());
  if (!m) return null;
  const [y, mo, d] = [Number(m[1]), Number(m[2]), Number(m[3])];
  const day = new Date(Date.UTC(y, mo - 1, d));
  if (mo < 1 || mo > 12 || d < 1 || day.getUTCMonth() !== mo - 1 || day.getUTCDate() !== d) return null;
  if (m[4] === undefined) return `${pad(y, 4)}-${pad(mo)}-${pad(d)}`;
  const [h, mi, sec] = [Number(m[4]), Number(m[5]), Number(m[6] ?? 0)];
  if (h > 23 || mi > 59 || sec > 59) return null;
  let off = 0;
  if (m[7] !== 'Z') {
    const digits = m[7].slice(1).replace(':', '');
    off = (Number(digits.slice(0, 2)) * 3600 + Number(digits.slice(2, 4)) * 60) * (m[7][0] === '-' ? -1 : 1);
  }
  return new Date(Date.UTC(y, mo - 1, d, h, mi, sec) - off * 1000).toISOString().replace(/\.\d+Z$/, 'Z');
}

/** A schedule file's rounds → clean entries in date order. Throws naming the round that can't be used. */
export function normalizeSchedule(rounds: unknown[]): ScheduleEntry[] {
  if (rounds.length > MAX_ROUNDS) throw new Error(`more than ${MAX_ROUNDS} rounds`);
  const out = rounds.map((raw, i): ScheduleEntry => {
    const n = i + 1;
    if (raw === null || typeof raw !== 'object' || Array.isArray(raw)) throw new Error(`round ${n} is not an object`);
    const r = raw as Record<string, unknown>;
    const track = text(r.track);
    if (track === null) throw new Error(`round ${n} needs a "track" name`);
    const date = typeof r.date === 'string' ? when(r.date) : null;
    if (date === null)
      throw new Error(`round ${n} needs a "date" like 2026-11-02, or with a time and UTC offset like 2026-11-02T19:30:00+11:00`);
    const config = text(r.config);
    return {
      date, track,
      config: config === null || config.toLowerCase() === 'n/a' ? null : config,
      track_id: whole(r.track_id),
      laps: whole(r.laps),
      minutes: whole(r.minutes),
      cars: text(r.cars),
      note: text(r.note),
    };
  });
  const cmp = (a: string, b: string) => (a < b ? -1 : a > b ? 1 : 0);
  return out.sort((a, b) => cmp(a.date, b.date) || cmp(a.track, b.track));
}

const secondsOf = (iso: string) => Date.parse(iso) / 1000;

export function buildCalendar(rounds: Round[], sched: ScheduleEntry[], known: Map<string, number>): CalendarEntry[] {
  const full = (e: ScheduleEntry) => e.track + (e.config !== null ? ` – ${e.config}` : '');

  // pair each scheduled round with the nearest unclaimed result at the same track
  const claimed = new Map<number, number>();
  const matched = new Set<number>();
  sched.forEach((e, i) => {
    let best: [number, number] | null = null;
    rounds.forEach((rd, k) => {
      if (claimed.has(k)) return;
      const same = e.track_id !== null
        ? rd.track_id === e.track_id
        : trackKey(rd.track_short) === trackKey(e.track) && (e.config === null || trackKey(rd.track) === trackKey(full(e)));
      if (!same) return;
      const diff = Math.abs(secondsOf(rd.date) - secondsOf(e.date));
      if (diff <= MATCH_WINDOW_S && (best === null || diff < best[1])) best = [k, diff];
    });
    if (best !== null) {
      claimed.set((best as [number, number])[0], i);
      matched.add(i);
    }
  });

  const cal: CalendarEntry[] = rounds.map((rd, k) => ({
    n: rd.round, status: 'done', round: rd.round, date: rd.date,
    track: rd.track, track_short: rd.track_short, track_id: rd.track_id,
    laps: rd.laps, minutes: null, cars: null, note: claimed.has(k) ? sched[claimed.get(k)!].note : null,
  }));
  let n = rounds.length;
  sched.forEach((e, i) => {
    if (matched.has(i)) return;
    cal.push({
      n: ++n, status: 'upcoming', round: null, date: e.date,
      track: full(e), track_short: e.track,
      track_id: e.track_id ?? known.get(trackKey(full(e))) ?? known.get(trackKey(e.track)) ?? null,
      laps: e.laps, minutes: e.minutes, cars: e.cars, note: e.note,
    });
  });
  return cal;
}

/** Track key → id for every track in these races; the lowest id wins a clash between layouts. */
export function knownTracks(races: Race[]): Map<string, number> {
  const known = new Map<string, number>();
  for (const r of races) {
    for (const key of [trackKey(r.track), trackKey(r.track_short)]) {
      known.set(key, Math.min(known.get(key) ?? Infinity, r.track_id));
    }
  }
  return known;
}
