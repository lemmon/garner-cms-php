<script>
  import ExternalLinkIcon from '@lucide/svelte/icons/external-link';

  import { invalidate } from '$app/navigation';
  import { page } from '$app/state';
  import Breadcrumbs from '$lib/components/Breadcrumbs.svelte';
  import Button from '$lib/components/Button.svelte';
  import EditableTitle from '$lib/components/EditableTitle.svelte';
  import Form from '$lib/components/forms/Form.svelte';
  import NodeEmptyState from '$lib/components/nodes/NodeEmptyState.svelte';
  import NodeErrorState from '$lib/components/nodes/NodeErrorState.svelte';
  import Tabs from '$lib/components/Tabs.svelte';
  import { nodeDefinitions } from '$lib/nodes/nodeDefinitions.js';

  let {
    title,
    slug = '',
    description = '',
    breadcrumbs = [],
    blueprint = undefined,
    blueprintIssue = '',
    fields = undefined,
    openHref = '',
    openLabel = 'Open page',
    editAction = '',
    contentAction = '',
    editId = '',
    invalidateKeys = [],
    slugEditable = false,
    editTitleLabel = 'Edit title and slug',
    toolbarActions,
  } = $props();
  const uid = $props.id();

  let tabs = $derived(blueprint?.tabs ?? []);
  let requestedTab = $derived(page.url.searchParams.get('tab') || '');
  let hasRequestedTab = $derived(requestedTab !== '');
  let hasValidRequestedTab = $derived(
    tabs.some((tab) => tab.name === requestedTab)
  );
  let activeTab = $derived(
    hasRequestedTab ? requestedTab : (tabs[0]?.name ?? '')
  );
  let invalidTabMessage = $derived(
    hasRequestedTab && !hasValidRequestedTab
      ? `Tab "${requestedTab}" is not available in this blueprint.`
      : ''
  );
  let allNodes = $derived(tabs.flatMap((tab) => tab.nodes ?? []));
  let hasSaveableNodes = $derived(
    Boolean(contentAction) &&
      allNodes.some((node) => nodeDefinitions[node.type]?.saveable === true)
  );
  let contentFormId = $derived(
    hasSaveableNodes ? `blueprint-content-form-${uid}` : ''
  );
  let contentLoading = $state(false);
  let contentErrors = $state({});
  let contentError = $state(null);

  async function handleContentSave() {
    await Promise.all(invalidateKeys.map((key) => invalidate(key)));
  }
</script>

<div class="space-y-12 p-12">
  <header class="space-y-3">
    <Breadcrumbs items={breadcrumbs} />

    {#if editAction}
      <EditableTitle
        {title}
        {slug}
        action={editAction}
        id={editId}
        {invalidateKeys}
        {slugEditable}
        editLabel={editTitleLabel}
      />
    {:else}
      <h1 class="text-5xl font-medium tracking-tight text-balance">
        {title}
      </h1>
    {/if}

    {#if description}
      <p class="max-w-2xl text-lg/6 text-current/60">
        {description}
      </p>
    {/if}
  </header>

  {#if blueprint}
    {#if hasSaveableNodes}
      <Form
        id={contentFormId}
        class="space-y-0"
        action={contentAction}
        bind:loading={contentLoading}
        bind:errors={contentErrors}
        bind:error={contentError}
        onsuccess={handleContentSave}
      >
        {#if editId}
          <input type="hidden" name="id" value={editId} />
        {/if}
      </Form>
    {/if}

    <Tabs items={tabs} value={activeTab}>
      {#snippet actions()}
        {#if toolbarActions}
          {@render toolbarActions()}
        {/if}
        {#if openHref}
          <Button
            class="p-2"
            kind="secondary"
            href={openHref}
            target="_blank"
            rel="noopener noreferrer"
            aria-label={openLabel}
          >
            <ExternalLinkIcon size={20} aria-hidden="true" />
          </Button>
        {/if}
        {#if hasSaveableNodes}
          <Button
            class="min-w-32 p-2 text-lg/5"
            type="submit"
            form={contentFormId}
            loading={contentLoading}
          >
            Save
          </Button>
        {/if}
      {/snippet}
    </Tabs>

    {#if invalidTabMessage}
      <NodeErrorState>{invalidTabMessage}</NodeErrorState>
    {:else}
      <div>
        {#each tabs as tab (tab.name)}
          {@const tabNodes = tab.nodes ?? []}
          {@const isActive = tab.name === activeTab}
          {#if tabNodes.length > 0}
            <div class="space-y-6" hidden={!isActive}>
              {#each tabNodes as node (node.name)}
                {@const definition = nodeDefinitions[node.type]}
                {@const NodeComponent = definition?.component}
                {#if NodeComponent}
                  <NodeComponent
                    {node}
                    value={fields?.[node.name] ?? ''}
                    error={definition?.saveable
                      ? contentErrors[node.name]
                      : undefined}
                    disabled={definition?.saveable ? contentLoading : undefined}
                    form={definition?.saveable ? contentFormId : undefined}
                  />
                {:else}
                  <NodeErrorState>
                    Unsupported node type "{node.type}" in this blueprint.
                  </NodeErrorState>
                {/if}
              {/each}
            </div>
          {:else if isActive}
            <NodeEmptyState>
              This tab does not define any editable fields yet.
            </NodeEmptyState>
          {/if}
        {/each}
      </div>
    {/if}
  {:else}
    <NodeErrorState>
      {blueprintIssue || 'Blueprint is not available.'}
    </NodeErrorState>
  {/if}
</div>
