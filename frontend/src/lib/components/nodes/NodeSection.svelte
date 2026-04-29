<script>
  import { twMerge } from 'tailwind-merge';

  let {
    class: classname = '',
    label = null,
    help = null,
    actions,
    children,
  } = $props();
</script>

<section class={twMerge('space-y-2', classname)}>
  {#if label || actions}
    <header class="flex flex-row justify-between gap-2">
      <div>
        {#if label}
          <h2 class="text-lg/6 font-medium">{label}</h2>
        {/if}
      </div>

      <div class="flex gap-2">
        {#if actions}
          {@render actions()}
        {/if}
      </div>
    </header>
  {/if}

  {@render children()}

  {#if help}
    <div class="py-0.5">
      <p class="text-base/5 text-current/60">{help}</p>
    </div>
  {/if}
</section>
