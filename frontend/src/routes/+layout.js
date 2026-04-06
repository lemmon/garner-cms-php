import { api } from '$lib/api';

export const ssr = false;

export async function load() {
  const data = await api('studio/site');

  return {
    site: data.site,
  };
}
