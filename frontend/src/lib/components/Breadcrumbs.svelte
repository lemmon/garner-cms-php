<script>
  import { resolve } from '$app/paths';
  import { page } from '$app/state';
  import { studioNavigationItems } from '$lib/studio/navigation.js';

  let { items = [] } = $props();

  let activeNavigationItem = $derived(
    studioNavigationItems.find((item) => {
      let resolvedHref = resolve(item.href);

      return (
        page.url.pathname === resolvedHref ||
        page.url.pathname.startsWith(resolvedHref + '/')
      );
    })
  );
</script>

{#if items.length > 0}
  <nav aria-label="Breadcrumb" class="mb-12 text-lg/6">
    <ol class="flex flex-wrap gap-x-2 gap-y-1">
      {#each items as item, index (item.label + ':' + index)}
        {@const Icon = activeNavigationItem?.icon}
        <li class="flex items-center gap-2 text-neutral-400">
          {#if index === 0 && Icon}
            <Icon size={20} />
          {/if}

          {#if item.href}
            <a
              class="-m-2 p-2 hover:text-neutral-600 hover:underline"
              href={resolve(item.href)}
            >
              {item.label}
            </a>
          {:else}
            <span>{item.label}</span>
          {/if}

          {#if index < items.length - 1}
            <span aria-hidden="true" class="text-current/20">/</span>
          {/if}
        </li>
      {/each}
    </ol>
  </nav>
{/if}
