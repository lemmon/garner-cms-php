<script>
  let { label = null, meta = null, help = null, children } = $props();
</script>

<section class="space-y-2">
  {#if label || meta}
    <header class="flex items-end justify-between gap-6">
      {#if label}
        <h2 class="text-lg/6 font-medium">{label}</h2>
      {/if}

      {#if meta}
        <p class="my-0.5 text-base/5 text-current/60">{meta}</p>
      {/if}
    </header>
  {/if}

  {@render children()}

  {#if help}
    <div class="py-0.5">
      <p class="text-base/5 text-current/60">{help}</p>
    </div>
  {/if}
</section>
