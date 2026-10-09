<script lang="ts">
  import { slotFor, useSeason } from '$lib/drivers';
  import Marker from './Marker.svelte';

  let { name, ai = false, label }: { name: string; ai?: boolean; label?: string } = $props();
  const season = useSeason();
  const slot = $derived(ai ? null : slotFor(season?.(), name));
</script>

<span class="inline-flex min-w-0 items-center gap-1.5 align-middle">
  <Marker {slot} />
  <span class="truncate">{label ?? name}</span>
  {#if ai}<span class="rounded border px-1 text-[10px] font-medium tracking-wide text-muted-foreground">AI</span>{/if}
</span>
