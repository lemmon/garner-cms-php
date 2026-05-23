<script>
  import { invalidate } from '$app/navigation';
  import BlueprintView from '$lib/components/BlueprintView.svelte';
  import PageStatusButton from '$lib/components/nodes/PageStatusButton.svelte';

  let { data } = $props();

  let pageInvalidateKeys = $derived([
    'studio:site',
    `studio:page:${data.page.id}`,
  ]);
  let publicHref = $derived(
    data.page.path ? data.site.url + data.page.path : ''
  );

  async function handleStatusSaved() {
    await Promise.all(pageInvalidateKeys.map((key) => invalidate(key)));
  }
</script>

<svelte:head>
  <title>{data.page.title} | {data.site.title}</title>
</svelte:head>

<BlueprintView
  title={data.page.title}
  slug={data.page.slug}
  description={data.blueprint?.description}
  breadcrumbs={data.breadcrumbs}
  blueprint={data.blueprint}
  blueprintIssue={data.blueprintIssue}
  fields={data.page.fields}
  editAction="studio/pages/update"
  contentAction="studio/pages/update"
  editId={data.page.id}
  invalidateKeys={pageInvalidateKeys}
  slugEditable={data.page.slug_editable}
  openHref={publicHref}
  openLabel={'Open ' + data.page.title}
>
  {#snippet toolbarActions()}
    {#if data.page.status_editable && data.page.status}
      <PageStatusButton
        item={data.page}
        siblings={data.statusSiblings}
        kind="secondary"
        class="min-w-24 p-2 text-lg/5"
        onsaved={handleStatusSaved}
      />
    {/if}
  {/snippet}
</BlueprintView>
