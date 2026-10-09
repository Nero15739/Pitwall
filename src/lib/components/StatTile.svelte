<script lang="ts">
  import type { Snippet } from 'svelte';

  /** label · value (+ unit) · one line of context. */
  let { label, value, unit, meta, children }: {
    label: string; value?: string | number; unit?: string; meta?: string; children?: Snippet;
  } = $props();
</script>

<div class="flex min-w-0 flex-col gap-1.5 rounded-xl border bg-card p-4 sm:p-5">
  <span class="text-xs font-medium text-muted-foreground">{label}</span>
  <span class="flex min-w-0 items-baseline gap-1.5 text-2xl font-semibold tracking-tight sm:text-[1.75rem]">
    {#if children}{@render children()}{:else}<span class="truncate">{value}</span>{/if}
    {#if unit}<span class="text-sm font-medium text-muted-foreground">{unit}</span>{/if}
  </span>
  {#if meta}<span class="text-[13px] text-muted-foreground">{meta}</span>{/if}
</div>
