<script lang="ts">
  import { setSeasonContext } from '$lib/drivers';

  let { data, children } = $props();
  setSeasonContext(() => data.season);
</script>

{@render children()}
