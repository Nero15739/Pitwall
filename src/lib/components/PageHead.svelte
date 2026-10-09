<script lang="ts">
  import type { Snippet } from 'svelte';

  let { title, sub, children }: { title: string; sub?: string; children?: Snippet } = $props();
</script>

<div class="mb-5 flex flex-wrap items-end justify-between gap-3 sm:mb-6">
  <div class="min-w-0">
    <h1 class="text-2xl font-semibold tracking-tight sm:text-[1.75rem]">{title}</h1>
    {#if sub}<p class="mt-1 max-w-3xl text-sm text-muted-foreground">{sub}</p>{/if}
  </div>
  {#if children}<div class="flex flex-wrap items-center gap-2">{@render children()}</div>{/if}
</div>
