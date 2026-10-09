<script lang="ts">
  import type { TrackMap } from '$shared/types';

  /** A small, quiet silhouette of the circuit. */
  let { map, class: klass = '' }: { map: TrackMap | null | undefined; class?: string } = $props();
</script>

{#if map}
  <svg viewBox={map.viewBox} class={klass} aria-hidden="true" preserveAspectRatio="xMidYMid meet">
    <path d={map.path} fill="none" stroke="currentColor" stroke-width="2.5" vector-effect="non-scaling-stroke" stroke-linejoin="round" />
  </svg>
{/if}
