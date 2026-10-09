<script lang="ts">
  import { page } from '$app/state';
</script>

<div class="mx-auto max-w-md py-16 text-center">
  <h1 class="text-xl font-semibold">{page.status === 404 ? 'Not found' : 'Something went wrong'}</h1>
  <p class="mt-2 text-sm text-muted-foreground">{page.error?.message}</p>
  <a href="/" class="mt-4 inline-block text-sm font-medium underline underline-offset-4">Back to the dashboard</a>
</div>
