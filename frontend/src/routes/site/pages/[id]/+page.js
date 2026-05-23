import { api } from '$lib/api';

export async function load({ depends, params }) {
  depends(`studio:page:${params.id}`);

  const detail = await api('studio/pages/show', {
    id: params.id,
  });

  return {
    blueprint: detail.blueprint,
    blueprintIssue: detail.blueprint_issue,
    breadcrumbs: detail.breadcrumbs,
    page: detail.page,
    statusSiblings: detail.status_siblings,
  };
}
