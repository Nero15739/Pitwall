<script lang="ts" module>
  import type { TipContent } from '$lib/tip.svelte';
  export interface Bar {
    key: string;
    label: string;
    value: number;
    text: string;          // value shown at the bar tip
    driver?: boolean;      // render the label as a driver tag
    ai?: boolean;
    slot?: number | null;  // colour slot; omit for the single-series colour
    tip?: TipContent;
  }
</script>

<script lang="ts">
  import { slotColor } from '$lib/drivers';
  import { tip } from '$lib/tip.svelte';
  import DriverTag from '../DriverTag.svelte';

  /* Horizontal bars, one baseline, ≤24px thick, 4px rounded data-end and the value at the tip.
     Every value is labelled, so the bars double as their own table. */
  let { bars, max, label, color }: { bars: Bar[]; max?: number; label: string; color?: string } = $props();
  const top = $derived(max ?? Math.max(...bars.map(b => b.value), 0));
</script>

<ul class="grid grid-cols-1 gap-2" aria-label={label}>
  {#each bars as b (b.key)}
    <li
      class="grid grid-cols-[minmax(0,8.5rem)_minmax(0,1fr)] items-center gap-3 rounded-md text-[13px] sm:grid-cols-[minmax(0,11rem)_minmax(0,1fr)]"
      use:tip={b.tip ?? null}
    >
      <span class="min-w-0 truncate">{#if b.driver}<DriverTag name={b.label} ai={b.ai} />{:else}{b.label}{/if}</span>
      <span class="flex min-w-0 items-center gap-2">
        <span
          class="h-3.5 shrink-0 rounded-r-[4px]"
          style="width:max(3px, calc((100% - 4.5rem) * {top > 0 ? Math.max(0, b.value) / top : 0})); background:{b.ai ? 'var(--ai)' : b.slot !== undefined ? slotColor(b.slot) : (color ?? 'var(--s0)')}"
        ></span>
        <span class="tnum shrink-0 text-muted-foreground">{b.text}</span>
      </span>
    </li>
  {/each}
</ul>
