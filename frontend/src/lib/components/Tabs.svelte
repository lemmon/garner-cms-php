<script>
  let { items = [], value = $bindable('') } = $props();

  const uid = $props.id();
  const groupName = `tabs-${uid}`;

  const tabs = $derived(
    Array.isArray(items)
      ? items.filter(
          (item) =>
            typeof item?.name === 'string' &&
            item.name !== '' &&
            typeof item?.label === 'string' &&
            item.label !== ''
        )
      : []
  );
</script>

{#if tabs.length > 0}
  <nav aria-label="Content tabs" class="border-b border-neutral-100">
    <ul class="-mb-px flex flex-row gap-2 text-lg/6 font-medium tracking-tight">
      {#each tabs as tab (tab.name)}
        <li>
          <label
            class={[
              'block px-3 pt-3 pb-2.5',
              'border-b-2 transition-colors',
              'cursor-pointer',
              value === tab.name
                ? 'text-blue border-blue'
                : 'border-transparent text-neutral-500 hover:text-current',
            ]}
          >
            <input
              bind:group={value}
              class="sr-only"
              name={groupName}
              type="radio"
              value={tab.name}
            />
            {tab.label}
          </label>
        </li>
      {/each}
    </ul>
  </nav>
{/if}
