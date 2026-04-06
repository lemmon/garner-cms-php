<script>
  let { children } = $props();
</script>

<div
  class="p-border-4 border border-dashed border-neutral-200 text-lg/6 text-current/60"
>
  {#if children}
    {@render children()}
  {:else}
    Nothing here yet.
  {/if}
</div>
