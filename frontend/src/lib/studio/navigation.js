import LayoutPanelTopIcon from '@lucide/svelte/icons/layout-panel-top';
import SlidersVerticalIcon from '@lucide/svelte/icons/sliders-vertical';
import UsersIcon from '@lucide/svelte/icons/users';

export const studioNavigationItems = [
  {
    href: '/site',
    label: 'Pages',
    icon: LayoutPanelTopIcon,
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
