<!--
  TODO: Figure out a caching mechanism (perhaps props from the parent) so list
  state survives remounts when switching tabs.
-->
<script>
  import FileTextIcon from '@lucide/svelte/icons/file-text';
  import HouseIcon from '@lucide/svelte/icons/house';

  import { api } from '$lib/api';
  import Button from '$lib/components/Button.svelte';
  import NodeEmptyState from '$lib/components/nodes/NodeEmptyState.svelte';
  import NodeErrorState from '$lib/components/nodes/NodeErrorState.svelte';
  import NodeSection from '$lib/components/nodes/NodeSection.svelte';
  import PageCreateButton from '$lib/components/nodes/PageCreateButton.svelte';
  import PageStatusButton from '$lib/components/nodes/PageStatusButton.svelte';
  import Shimmer from '$lib/components/Shimmer.svelte';

  let { node } = $props();
  let refreshKey = $state(0);

  async function loadData(node, refreshKey) {
    void refreshKey;

    return await api('studio/nodes/query', {
      type: node.type,
      source: node.source,
      query: node.query,
    });
  }
</script>

<NodeSection class="not-last:mb-12" label={node.label} help={node.help}>
  {#snippet actions()}
    {#if node.create?.enabled}
      <PageCreateButton source={node.source} />
    {/if}
  {/snippet}

  {#await loadData(node, refreshKey)}
    <Shimmer class="h-14 w-full" />
  {:then data}
    {#if data.items.length > 0}
      <div class="border-t border-neutral-100">
        {#each data.items as item (item.id)}
          <article class="flex flex-row border-b border-neutral-100 text-lg/6">
            <div class="py-border-4 flex px-4">
              {#if item.is_home}
                <HouseIcon class="text-blue" />
              {:else}
                <FileTextIcon class="text-blue" />
              {/if}
            </div>
            <h2 class="flex flex-1">
              <Button
                class="py-border-4 -ml-2 flex-1 pl-2 text-lg/6 underline"
                align="start"
                kind="ghost"
                href="/site/pages/{item.id}">{item.title}</Button
              >
            </h2>
            {#if item.status}
              <PageStatusButton
                {item}
                siblings={data.items}
                onsaved={() => {
                  refreshKey += 1;
                }}
              />
            {/if}
          </article>
        {/each}
      </div>
    {:else}
      <NodeEmptyState>{node.empty || 'No pages yet.'}</NodeEmptyState>
    {/if}
  {:catch error}
    <NodeErrorState {error} />
  {/await}
</NodeSection>
