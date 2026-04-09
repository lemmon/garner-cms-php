<script>
  import { resolve } from '$app/paths';
  import { page } from '$app/state';
  import { studioNavigationItems as items } from '$lib/studio/navigation.js';
</script>

<aside
  class="shrink-0 overflow-y-auto border-r border-neutral-200 bg-neutral-50"
>
  <nav aria-label="Studio navigation">
    <ul class="space-y-0.5 p-3">
      {#each items as item (item.href)}
        {@const resolvedHref = resolve(item.href)}
        {@const Icon = item.icon}
        {@const active =
          page.url.pathname === resolvedHref ||
          page.url.pathname.startsWith(resolvedHref + '/')}
        <li>
          <a
            class={[
              'flex p-3 transition-colors',
              active
                ? 'text-blue bg-blue/5'
                : 'text-neutral-600 hover:bg-neutral-100 hover:text-current',
            ]}
            href={resolvedHref}
            aria-current={active ? 'page' : undefined}
            aria-label={item.label}
            title={item.label}
          >
            <Icon />
            <span class="sr-only">{item.label}</span>
          </a>
        </li>
      {/each}
    </ul>
  </nav>
</aside>
