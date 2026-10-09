<script lang="ts">
  import { tip, type TipContent } from '$lib/tip.svelte';

  /** A table cell shaded on the sequential ramp (7 steps), with the highest-contrast ink. */
  let { value, min, max, text, tipContent }: {
    value: number | null; min: number; max: number; text: string; tipContent?: TipContent | null;
  } = $props();
  const step = $derived(value == null || !Number.isFinite(value) ? 0 : 1 + Math.round((max > min ? (value - min) / (max - min) : 0) * 6));
</script>

<td
  class="tnum px-2 py-1.5 text-center text-[13px] font-medium {step ? '' : 'text-muted-foreground'}"
  style={step ? `background:var(--seq-${step}00);color:var(--heat-ink-${step})` : ''}
  tabindex={tipContent ? 0 : undefined}
  use:tip={tipContent ?? null}
>{text}</td>
