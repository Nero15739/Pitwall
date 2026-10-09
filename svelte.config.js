import adapter from '@sveltejs/adapter-static';
import { vitePreprocess } from '@sveltejs/vite-plugin-svelte';

/** Static SPA: the UI builds to public/app, data is served separately from public/data. */
export default {
  preprocess: vitePreprocess(),
  kit: {
    adapter: adapter({ pages: 'public/app', assets: 'public/app', fallback: 'index.html', strict: false }),
    alias: { $shared: 'src/lib/shared' },
  },
};
