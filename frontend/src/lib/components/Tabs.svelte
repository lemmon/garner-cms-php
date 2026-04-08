<script>
  import { page } from '$app/state';

  let { items = [], value = '' } = $props();
</script>

{#if items.length > 0}
  <nav aria-label="Content tabs" class="border-b border-neutral-100">
    <ul class="-mb-px flex flex-row gap-2 text-lg/6 font-medium tracking-tight">
      {#each items as tab (tab.name)}
        <li>
          <!--
            TODO: Refactor tab links to use `resolve()` once current-route/base-path
            handling stops duplicating the Studio base path for these URLs.
          -->
          <!-- eslint-disable svelte/no-navigation-without-resolve -->
          <a
            href={page.url.pathname + '?tab=' + tab.name}
            data-sveltekit-noscroll
            data-sveltekit-replacestate
            class={[
              'block px-3 pt-3 pb-2.5',
              'border-b-2 transition-colors',
              value === tab.name
                ? 'text-blue border-blue'
                : 'border-transparent text-neutral-500 hover:text-current',
            ]}
          >
            {tab.label}
          </a>
          <!-- eslint-enable svelte/no-navigation-without-resolve -->
        </li>
      {/each}
    </ul>
  </nav>
{/if}
