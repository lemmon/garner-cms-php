<script>
  import { twMerge } from 'tailwind-merge';

  let {
    class: classname,
    disabled,
    optional = false,
    actions,
    children,
    ...props
  } = $props();
</script>

<div class="flex flex-row justify-between gap-2">
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
  {#if actions}
    {@render actions()}
  {/if}
</div>
