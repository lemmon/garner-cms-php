<!--
  TODO: Figure out a caching mechanism (perhaps props from the parent) so list
  state survives remounts when switching tabs.
-->
<script>
  import FileTextIcon from '@lucide/svelte/icons/file-text';
  import HouseIcon from '@lucide/svelte/icons/house';

  import { resolve } from '$app/paths';
  import { api } from '$lib/api';
  import NodeEmptyState from '$lib/components/nodes/NodeEmptyState.svelte';
  import NodeErrorState from '$lib/components/nodes/NodeErrorState.svelte';
  import NodeSection from '$lib/components/nodes/NodeSection.svelte';
  import PageCreateButton from '$lib/components/nodes/PageCreateButton.svelte';
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

<NodeSection class="not-last:mb-12" label={node.label} help={node.help}>
  {#snippet actions()}
    {#if node.create?.enabled}
      <PageCreateButton source={node.source} />
    {/if}
  {/snippet}

  {#await loadData(node)}
    <Shimmer class="h-14 w-full" />
  {:then data}
    {#if data.items.length > 0}
      <div class="border-t border-neutral-100">
        {#each data.items as item (item.id)}
          {@const detailHref = resolve(`/site/pages/${item.id}`)}
          <article class="flex flex-row border-b border-neutral-100 text-lg/6">
            <div class="py-border-4 flex px-4">
              {#if item.is_home}
                <HouseIcon class="text-blue" />
              {:else}
                <FileTextIcon class="text-blue" />
              {/if}
            </div>
            <h2 class="flex flex-1">
              <a class="py-border-4 flex-1 underline" href={detailHref}
                >{item.title}</a
              >
            </h2>
            {#if item.status}
              <div class="py-border-4 px-4 text-neutral-500">{item.status}</div>
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
