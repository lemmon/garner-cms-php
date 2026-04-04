<script>
  // import ChevronDownIcon from '@lucide/svelte/icons/chevron-down';
  // import GripVerticalIcon from '@lucide/svelte/icons/grip-vertical';
  import FileTextIcon from '@lucide/svelte/icons/file-text';
  import HouseIcon from '@lucide/svelte/icons/house';

  let { data } = $props();
</script>

<svelte:head>
  <title>Site | Garner Studio</title>
</svelte:head>

<div class="space-y-12 p-12">
  <h1 class="text-4xl font-medium tracking-tight text-balance">
    {data.site.title}
  </h1>

  <div class="border-t border-neutral-100">
    {#each data.pages as page (page.id)}
      <article class="flex flex-row gap-3 border-b border-neutral-100 px-3">
        <!--
        <div class="py-3">
          {#if page.parent_id}
            <GripVerticalIcon class="text-neutral-200" />
          {:else}
            <ChevronDownIcon class="text-neutral-200" />
          {/if}
        </div>
        -->
        <div class="flex flex-1 flex-row items-center gap-3 py-3 text-lg/6">
          {#if page.is_home}
            <HouseIcon class="text-blue" />
          {:else}
            <FileTextIcon class="text-blue" />
          {/if}
          <h2 class="flex-1 underline">{page.title}</h2>
          <div class="text-neutral-500">{page.status}</div>
        </div>
      </article>
    {/each}
  </div>
</div>
