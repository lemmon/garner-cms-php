import { api } from '$lib/api';

export async function load() {
  const data = await api('studio/bootstrap');

  return {
    site: data.site,
    pages: data.pages,
    stats: data.stats,
  };
}
