import { error } from '@sveltejs/kit';
import type { Season } from '$shared/types';

export async function load({ params, fetch, parent, depends }) {
  depends('pitwall:data');
  const { index } = await parent();
  const entry = index.seasons.find(s => s.slug === params.season);
  if (!entry) error(404, `There's no season called "${params.season}".`);
  const r = await fetch(`/data/${entry.file}`, { cache: 'no-cache' });
  if (!r.ok) error(503, `Couldn't load ${entry.file} (${r.status}).`);
  return { season: (await r.json()) as Season };
}
