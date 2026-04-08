import { api } from '$lib/api';

export async function load({ params }) {
  const detail = await api('studio/pages/show', {
    id: params.id,
  });

  return {
    blueprint: detail.blueprint,
    blueprintIssue: detail.blueprint_issue,
    page: detail.page,
  };
}
