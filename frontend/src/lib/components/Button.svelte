<script>
  let {
    class: classname = '',
    disabled = false,
    href,
    kind = 'primary',
    loading = false,
    type = 'button',
    children,
    ...props
  } = $props();

  const variants = {
    ghost: 'bg-transparent text-current outline-transparent hover:bg-black/5',
    primary:
      'bg-blue text-white outline-blue hover:bg-blue/90 focus-visible:outline-blue',
    secondary:
      'bg-white/80 text-current outline-neutral-900/15 hover:bg-white focus-visible:outline-blue',
  };
</script>

<svelte:element
  this={href ? 'a' : 'button'}
  class={[
    'relative inline-flex items-center justify-center gap-2 rounded-full px-5 py-3 text-base/5 font-medium tracking-tight outline-1 -outline-offset-1 transition-colors focus-visible:outline-2 focus-visible:-outline-offset-2',
    variants[kind] ?? variants.primary,
    disabled || loading ? 'cursor-not-allowed opacity-70' : 'cursor-pointer',
    classname,
  ]}
  {href}
  disabled={href ? undefined : disabled || loading}
  aria-busy={loading ? 'true' : undefined}
  type={href ? undefined : type}
  {...props}
>
  <span class:invisible={loading}>
    {@render children()}
  </span>

  {#if loading}
    <span class="absolute inset-0 flex items-center justify-center">
      <span
        class="h-5 w-5 animate-spin rounded-full border-2 border-current border-t-transparent border-r-transparent"
      ></span>
    </span>
  {/if}
</svelte:element>
