<script>
  import NodeEmptyState from '$lib/components/nodes/NodeEmptyState.svelte';
  import NodeErrorState from '$lib/components/nodes/NodeErrorState.svelte';
  import TextareaNode from '$lib/components/nodes/TextareaNode.svelte';
  import TextNode from '$lib/components/nodes/TextNode.svelte';
  import Tabs from '$lib/components/Tabs.svelte';

  let { data } = $props();

  let page = $derived(data.page);
  let blueprint = $derived(data.blueprint);
  let tabs = $derived(blueprint?.tabs ?? []);
  let activeTab = $state('');
  let activeTabBlueprint = $derived(tabs.find((tab) => tab.name === activeTab));
  let activeNodes = $derived(activeTabBlueprint?.nodes ?? []);
  let title = $state('');
  let fieldValues = $state({});

  const nodeComponents = {
    text: TextNode,
    textarea: TextareaNode,
  };

  $effect(() => {
    title = page.title;
    fieldValues = structuredClone(page.fields ?? {});
  });

  $effect(() => {
    if (!tabs.some((tab) => tab.name === activeTab)) {
      activeTab = tabs[0]?.name ?? '';
    }
  });
</script>

<svelte:head>
  <title>{title || page.title} | {data.site.title}</title>
</svelte:head>

<div class="space-y-12 p-12">
  <header class="space-y-3">
    <h1 class="text-4xl font-medium tracking-tight text-balance">
      {title || page.title}
    </h1>

    {#if blueprint?.description}
      <p class="max-w-2xl text-lg/6 text-current/60">
        {blueprint.description}
      </p>
    {/if}
  </header>

  {#if blueprint}
    <Tabs items={tabs} bind:value={activeTab} />

    {#if activeNodes.length > 0}
      <div class="space-y-6">
        {#each activeNodes as node (node.name)}
          {@const NodeComponent = nodeComponents[node.type]}
          {#if NodeComponent}
            <NodeComponent {node} bind:value={fieldValues[node.name]} />
          {:else}
            <NodeErrorState>
              Unsupported node type "{node.type}" in page blueprint.
            </NodeErrorState>
          {/if}
        {/each}
      </div>
    {:else}
      <NodeEmptyState>
        This tab does not define any editable fields yet.
      </NodeEmptyState>
    {/if}
  {:else}
    <NodeErrorState>
      {data.blueprintIssue ?? `Blueprint "${page.blueprint}" is not available.`}
    </NodeErrorState>
  {/if}
</div>
