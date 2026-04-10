<script>
  import { cva } from 'class-variance-authority';
  import { twMerge } from 'tailwind-merge';

  import { resolve } from '$app/paths';

  let {
    class: classname,
    href,
    kind,
    disabled,
    loading,
    type,
    children,
    ...props
  } = $props();

  const variant = cva(
    [
      'relative',
      'text-current',
      'flex items-center justify-center p-4 text-xl/6',
      'focus:outline-2 focus:-outline-offset-2 focus:outline-blue focus:shadow-[inset_0_0_0_3px_#fff]',
      'disabled:not-aria-busy:cursor-not-allowed disabled:not-aria-busy:bg-neutral-300 disabled:not-aria-busy:text-neutral-500/50',
      'cursor-pointer aria-busy:cursor-progress',
    ],
    {
      variants: {
        kind: {
          primary: 'bg-blue text-white',
          secondary: 'bg-neutral-500/10',
          warning: 'bg-neutral-500/10 text-red',
          destructive: 'bg-red text-white',
          ghost: 'bg-transparent hover:not-disabled:bg-neutral-500/10',
        },
      },
      defaultVariants: {
        kind: 'primary',
      },
    }
  );
</script>

<svelte:element
  this={href ? 'a' : 'button'}
  class={twMerge(variant({ kind }), classname)}
  href={href && !(disabled || loading)
    ? href.includes('://')
      ? href
      : resolve(href)
    : undefined}
  disabled={href ? undefined : disabled || loading}
  type={href ? undefined : (type ?? 'button')}
  aria-disabled={href ? disabled || loading : undefined}
  aria-busy={loading}
  tabindex={href && (disabled || loading) ? -1 : undefined}
  {...props}
>
  <span class="flex items-center justify-center gap-1" class:invisible={loading}
    >{@render children()}</span
  >
  {#if loading}
    <span class="absolute inset-0 flex items-center justify-center"
      ><span
        class="aspect-square h-lh animate-spin rounded-full border-2 border-current border-t-transparent border-r-transparent"
      ></span></span
    >
  {/if}
</svelte:element>
