<script>
  import { page } from '$app/state';
  import NodeEmptyState from '$lib/components/nodes/NodeEmptyState.svelte';
  import NodeErrorState from '$lib/components/nodes/NodeErrorState.svelte';
  import Tabs from '$lib/components/Tabs.svelte';
  import { nodeComponents } from '$lib/nodes/nodeComponents.js';

  let {
    title,
    description = '',
    blueprint = undefined,
    blueprintIssue = '',
    fields = undefined,
  } = $props();

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
  let activeTabBlueprint = $derived(tabs.find((tab) => tab.name === activeTab));
  let activeNodes = $derived(activeTabBlueprint?.nodes ?? []);
</script>

<div class="space-y-12 p-12">
  <header class="space-y-3">
    <h1 class="text-4xl font-medium tracking-tight text-balance">
      {title}
    </h1>

    {#if description}
      <p class="max-w-2xl text-lg/6 text-current/60">
        {description}
      </p>
    {/if}
  </header>

  {#if blueprint}
    <Tabs items={tabs} value={activeTab} />

    {#if invalidTabMessage}
      <NodeErrorState>{invalidTabMessage}</NodeErrorState>
    {:else if activeNodes.length > 0}
      {#each activeNodes as node (node.name)}
        {@const NodeComponent = nodeComponents[node.type]}
        {#if NodeComponent}
          <NodeComponent {node} value={fields?.[node.name] ?? ''} />
        {:else}
          <NodeErrorState>
            Unsupported node type "{node.type}" in this blueprint.
          </NodeErrorState>
        {/if}
      {/each}
    {:else}
      <NodeEmptyState>
        This tab does not define any editable fields yet.
      </NodeEmptyState>
    {/if}
  {:else}
    <NodeErrorState>
      {blueprintIssue || 'Blueprint is not available.'}
    </NodeErrorState>
  {/if}
</div>
