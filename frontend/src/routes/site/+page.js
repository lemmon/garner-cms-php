import { api } from '$lib/api';

export async function load() {
  const blueprint = await api('studio/blueprints/site');

  return {
    blueprint: blueprint.blueprint,
  };
}
