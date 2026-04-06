<script>
  import FileListNode from '$lib/components/nodes/FileListNode.svelte';
  import PageListNode from '$lib/components/nodes/PageListNode.svelte';
  import Tabs from '$lib/components/Tabs.svelte';

  let { data } = $props();

  let tabs = $derived(data.blueprint.tabs ?? []);
  let activeTab = $state('');
  let activeTabBlueprint = $derived(tabs.find((t) => t.name === activeTab));
  let activeNodes = $derived(activeTabBlueprint?.nodes ?? []);

  const nodeComponents = {
    file_list: FileListNode,
    page_list: PageListNode,
  };

  $effect(() => {
    if (activeTab === '' && tabs[0]?.name) {
      activeTab = tabs[0].name;
    }
  });
</script>

<svelte:head>
  <title>Site | {data.site.title}</title>
</svelte:head>

<div class="space-y-12 p-12">
  <header class="space-y-3">
    <h1 class="text-4xl font-medium tracking-tight text-balance">
      {data.site.title}
    </h1>
    {#if data.blueprint.description}
      <p class="max-w-2xl text-lg/6 text-current/60">
        {data.blueprint.description}
      </p>
    {/if}
  </header>

  <Tabs items={tabs} bind:value={activeTab} />

  {#each activeNodes as node (node.name)}
    {@const NodeComponent = nodeComponents[node.type]}
    {#if NodeComponent}
      <NodeComponent {node} />
    {:else}
      <section class="space-y-2">
        <header class="flex items-end justify-between gap-6">
          <h2 class="text-lg/6 font-medium tracking-tight">{node.label}</h2>
          <p class="my-0.5 text-base/5 text-current/60">{node.type}</p>
        </header>

        <div
          class="border-t border-b border-neutral-100 px-3 py-6 text-current/60"
        >
          Unsupported node type in Studio.
        </div>
      </section>
    {/if}
  {/each}
</div>
