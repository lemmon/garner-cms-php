<!--
  TODO: Figure out a caching mechanism (perhaps props from the parent) so list
  state survives remounts when switching tabs.
-->
<script>
  import FileTextIcon from '@lucide/svelte/icons/file-text';
  import HouseIcon from '@lucide/svelte/icons/house';

  import { api } from '$lib/api';
  import NodeEmptyState from '$lib/components/nodes/NodeEmptyState.svelte';
  import NodeErrorState from '$lib/components/nodes/NodeErrorState.svelte';
  import NodeSection from '$lib/components/nodes/NodeSection.svelte';
  import Shimmer from '$lib/components/Shimmer.svelte';

  let { node } = $props();

  async function loadData(node) {
    return await api('studio/nodes/query', {
      type: node.type,
      source: node.source,
      query: node.query,
    });
  }
</script>

<NodeSection label={node.label} help={node.help}>
  {#await loadData(node)}
    <Shimmer class="h-14 w-full" />
  {:then data}
    {#if data.items.length > 0}
      <div class="border-t border-neutral-100">
        {#each data.items as item (item.id)}
          <article
            class="py-border-4 flex flex-row gap-3 border-b border-neutral-100 px-4"
          >
            <div class="flex flex-1 flex-row items-center gap-3 text-lg/6">
              {#if item.is_home}
                <HouseIcon class="text-blue" />
              {:else}
                <FileTextIcon class="text-blue" />
              {/if}
              <h2 class="flex-1 underline">{item.title}</h2>
              {#if item.status}
                <div class="text-neutral-500">{item.status}</div>
              {/if}
            </div>
          </article>
        {/each}
      </div>
    {:else}
      <NodeEmptyState>{node.empty || 'No pages yet.'}</NodeEmptyState>
    {/if}
  {:catch error}
    <NodeErrorState>{error.message}</NodeErrorState>
  {/await}
</NodeSection>
