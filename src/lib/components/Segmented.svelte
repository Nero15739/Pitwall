<script lang="ts" generics="T extends string">
  /** A compact single-choice control: a group of pressed-state buttons. */
  let { value = $bindable(), options, label }: {
    value: T; options: { value: T; label: string }[]; label: string;
  } = $props();
</script>

<div role="group" aria-label={label} class="inline-flex flex-wrap gap-0.5 rounded-lg bg-muted p-0.5">
  {#each options as o (o.value)}
    <button
      type="button"
      aria-pressed={value === o.value}
      class="rounded-md px-2.5 py-1 text-[13px] font-medium text-muted-foreground transition-colors hover:text-foreground aria-pressed:bg-raised aria-pressed:text-foreground aria-pressed:shadow-sm"
      onclick={() => (value = o.value)}
    >{o.label}</button>
  {/each}
</div>
