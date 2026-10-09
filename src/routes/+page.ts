import { redirect } from '@sveltejs/kit';

/** The home page is the most recent season. */
export async function load({ parent }) {
  const { index } = await parent();
  if (index.seasons.length) redirect(307, `/${index.seasons[0].slug}`);
  return {};
}
