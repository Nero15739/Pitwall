<script lang="ts">
  import type { Snippet } from 'svelte';
  import { cn } from '$lib/utils';

  /** A titled card. `actions` sits top-right (filters scoped to this panel). */
  let { title, sub, actions, children, class: klass = '' }: {
    title?: string; sub?: string; actions?: Snippet; children: Snippet; class?: string;
  } = $props();
</script>

<section class={cn('min-w-0 rounded-xl border bg-card p-4 sm:p-5', klass)}>
  {#if title || actions}
    <header class="mb-4 flex flex-wrap items-start justify-between gap-3">
      <div class="min-w-0">
        {#if title}<h2 class="text-[15px] font-semibold tracking-tight">{title}</h2>{/if}
        {#if sub}<p class="mt-0.5 text-[13px] text-muted-foreground">{sub}</p>{/if}
      </div>
      {#if actions}<div class="flex flex-wrap items-center gap-2">{@render actions()}</div>{/if}
    </header>
  {/if}
  {@render children()}
</section>
