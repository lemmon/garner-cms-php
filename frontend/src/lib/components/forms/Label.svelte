<script>
  let {
    class: classname = '',
    disabled = false,
    optional = false,
    children,
    ...props
  } = $props();
</script>

<label
  class={[
    'block text-base/5 font-medium tracking-tight',
    disabled ? 'text-current/40' : 'text-current',
    classname,
  ]}
  {...props}
>
  {@render children()}
  {#if optional}
    <span class="text-current/50"> (optional)</span>
  {/if}
</label>
