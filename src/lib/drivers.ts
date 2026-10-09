/* Driver identity: a colour slot plus a marker shape, so identity never rests on colour alone.
   Slots come from the compiler and are stable across seasons. */
import { getContext, setContext } from 'svelte';
import type { Season } from '$shared/types';

export const slotColor = (slot: number | null | undefined) => (slot == null ? 'var(--ai)' : `var(--s${slot % 8})`);

export type Shape = 'circle' | 'square' | 'triangle' | 'diamond' | 'star' | 'x' | 'rounded' | 'plus';
const SHAPES: Shape[] = ['circle', 'square', 'triangle', 'diamond', 'star', 'x', 'rounded', 'plus'];
export const shapeOf = (slot: number | null | undefined): Shape => SHAPES[(slot ?? 0) % 8];

/** SVG path for a shape centred on (0,0) with radius r. Strokes for x/plus, fills for the rest. */
export function shapePath(shape: Shape, r: number): { d: string; stroke: boolean } {
  const k = r * 0.86;
  switch (shape) {
    case 'circle': return { d: `M${-r},0a${r},${r} 0 1,0 ${2 * r},0a${r},${r} 0 1,0 ${-2 * r},0`, stroke: false };
    case 'square': return { d: `M${-k},${-k}h${2 * k}v${2 * k}h${-2 * k}Z`, stroke: false };
    case 'rounded': {
      const c = k * 0.45;
      return { d: `M${-k + c},${-k}h${2 * (k - c)}q${c},0 ${c},${c}v${2 * (k - c)}q0,${c} ${-c},${c}h${-2 * (k - c)}q${-c},0 ${-c},${-c}v${-2 * (k - c)}q0,${-c} ${c},${-c}Z`, stroke: false };
    }
    case 'triangle': return { d: `M0,${-r * 1.1}L${r * 1.05},${r * 0.75}H${-r * 1.05}Z`, stroke: false };
    case 'diamond': return { d: `M0,${-r * 1.15}L${r * 1.15},0L0,${r * 1.15}L${-r * 1.15},0Z`, stroke: false };
    case 'star': {
      const pts = Array.from({ length: 10 }, (_, i) => {
        const a = -Math.PI / 2 + (i * Math.PI) / 5, rr = i % 2 ? r * 0.5 : r * 1.15;
        return `${(Math.cos(a) * rr).toFixed(2)},${(Math.sin(a) * rr).toFixed(2)}`;
      });
      return { d: `M${pts.join('L')}Z`, stroke: false };
    }
    case 'x': return { d: `M${-k},${-k}L${k},${k}M${k},${-k}L${-k},${k}`, stroke: true };
    case 'plus': return { d: `M0,${-r}V${r}M${-r},0H${r}`, stroke: true };
  }
}

// ---------------------------------------------------------------- season context
const KEY = Symbol('season');
export const setSeasonContext = (get: () => Season) => setContext(KEY, get);
export const useSeason = () => getContext<() => Season>(KEY);

export function slotFor(season: Season | undefined, name: string): number | null {
  return season?.drivers.find(d => d.name === name)?.color ?? null;
}
