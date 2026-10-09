<script lang="ts">
  import { tipState } from '$lib/tip.svelte';

  let el = $state<HTMLDivElement>();
  let w = $state(0), h = $state(0);
  const pad = 14;
  const pos = $derived.by(() => {
    if (typeof window === 'undefined') return { x: 0, y: 0 };
    let x = tipState.x + pad, y = tipState.y + pad;
    if (x + w > innerWidth - 8) x = tipState.x - w - pad;
    if (y + h > innerHeight - 8) y = tipState.y - h - pad;
    return { x: Math.max(8, x), y: Math.max(8, y) };
  });
</script>

{#if tipState.open && tipState.content}
  {@const c = tipState.content}
  <div
    bind:this={el}
    bind:clientWidth={w}
    bind:clientHeight={h}
    role="tooltip"
    class="pointer-events-none fixed z-50 max-w-72 rounded-lg border bg-popover px-3 py-2 text-xs text-popover-foreground shadow-lg"
    style="left:{pos.x}px; top:{pos.y}px"
  >
    <div class="font-semibold">{c.title}</div>
    {#if c.lines?.length}
      <div class="mt-1 grid gap-0.5">
        {#each c.lines as l, i (i)}
          <div class="flex items-center gap-2">
            {#if l.color}<span class="h-0.5 w-3 shrink-0 rounded-full" style="background:{l.color}"></span>{/if}
            <span class="tnum font-semibold">{l.value}</span>
            {#if l.label}<span class="text-muted-foreground">{l.label}</span>{/if}
          </div>
        {/each}
      </div>
    {/if}
    {#if c.note}<div class="mt-1 text-muted-foreground">{c.note}</div>{/if}
  </div>
{/if}
