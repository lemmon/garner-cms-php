<script>
  let { is = 'div', class: classname = '', children, ...props } = $props();
</script>

<svelte:element
  this={is}
  class={[
    'rounded-[1.5rem] border border-neutral-900/10 bg-white/76 shadow-[0_1.5rem_3rem_rgba(55,40,27,0.08)] backdrop-blur-sm',
    classname,
  ]}
  {...props}
>
  {@render children()}
</svelte:element>
