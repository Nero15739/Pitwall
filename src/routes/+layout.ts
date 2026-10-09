import { error } from '@sveltejs/kit';
import type { SeasonIndex, TracksFile } from '$shared/types';

// A static single-page app: data is fetched in the browser from /data (compiled JSON).
export const ssr = false;
export const prerender = false;

export async function load({ fetch, depends }) {
  depends('pitwall:data');
  const get = async <T>(url: string): Promise<T> => {
    const r = await fetch(url, { cache: 'no-cache' }); // revalidates with the server's ETag
    if (!r.ok) error(r.status === 404 ? 503 : r.status, `Couldn't load ${url} (${r.status}). Run "npm run compile" and serve the site.`);
    return r.json();
  };
  const [index, tracks] = await Promise.all([
    get<SeasonIndex>('/data/seasons.json'),
    get<TracksFile>('/data/tracks.json').catch(() => null),
  ]);
  return { index, tracks };
}
