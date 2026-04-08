<script>
  import { twMerge } from 'tailwind-merge';

  let {
    class: classname,
    disabled,
    optional = false,
    children,
    ...props
  } = $props();
</script>

<label
  class={twMerge(
    'block text-lg/6 font-medium data-disabled:text-current/20',
    classname
  )}
  data-disabled={disabled}
  {...props}
>
  {@render children()}
  {#if optional}
    <span class="text-current/50"> (optional)</span>
  {/if}
</label>
