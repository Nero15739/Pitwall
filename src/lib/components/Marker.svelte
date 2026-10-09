<script lang="ts">
  import { shapeOf, shapePath, slotColor } from '$lib/drivers';

  /** A driver's marker: their colour slot and shape. slot null = AI / unknown (grey dot). */
  let { slot, size = 11 }: { slot: number | null; size?: number } = $props();
  const color = $derived(slotColor(slot));
  const s = $derived(shapePath(shapeOf(slot), 4.4));
</script>

<svg class="shrink-0" width={size} height={size} viewBox="-6 -6 12 12" aria-hidden="true">
  {#if s.stroke}
    <path d={s.d} stroke={color} stroke-width="2.4" stroke-linecap="round" fill="none" />
  {:else}
    <path d={s.d} fill={color} />
  {/if}
</svg>
