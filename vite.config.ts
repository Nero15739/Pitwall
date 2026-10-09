import { sveltekit } from '@sveltejs/kit/vite';
import tailwindcss from '@tailwindcss/vite';
import sirv from 'sirv';
import { defineConfig, type Plugin } from 'vite';
import { loadConfig } from './tools/lib/config.ts';

/** In dev and preview, serve the compiled JSON at /data like nginx does in production. */
function pitwallData(): Plugin {
  const serve = sirv(loadConfig().dataOut, { dev: true, etag: true });
  return {
    name: 'pitwall-data',
    configureServer: s => void s.middlewares.use('/data', serve),
    configurePreviewServer: s => void s.middlewares.use('/data', serve),
  };
}

export default defineConfig({
  plugins: [pitwallData(), tailwindcss(), sveltekit()],
});
