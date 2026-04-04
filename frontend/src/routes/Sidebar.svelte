<script>
  import GlobeIcon from '@lucide/svelte/icons/globe';
  import SlidersVerticalIcon from '@lucide/svelte/icons/sliders-vertical';
  import UsersIcon from '@lucide/svelte/icons/users';

  import { resolve } from '$app/paths';
  import { page } from '$app/state';

  const items = [
    {
      href: '/',
      label: 'Pages',
      icon: GlobeIcon,
    },
    {
      href: '/users',
      label: 'Users',
      icon: UsersIcon,
    },
    {
      href: '/system',
      label: 'System',
      icon: SlidersVerticalIcon,
    },
  ];
</script>

<aside
  class="shrink-0 overflow-y-auto border-r border-neutral-200 bg-neutral-50"
>
  <nav aria-label="Studio navigation">
    <ul class="space-y-0.5 p-3">
      {#each items as item (item.href)}
        {@const resolvedHref = resolve(item.href)}
        {@const active =
          item.href === '/'
            ? page.url.pathname === resolvedHref
            : page.url.pathname === resolvedHref ||
              page.url.pathname.startsWith(resolvedHref + '/')}
        <li>
          <a
            class={[
              'flex p-3 transition-colors',
              active
                ? 'text-blue bg-blue/5'
                : 'text-current/60 hover:bg-neutral-100 hover:text-current',
            ]}
            href={resolvedHref}
            aria-current={active ? 'page' : undefined}
            aria-label={item.label}
            title={item.label}
          >
            <svelte:component this={item.icon} />
            <span class="sr-only">{item.label}</span>
          </a>
        </li>
      {/each}
    </ul>
  </nav>
</aside>
