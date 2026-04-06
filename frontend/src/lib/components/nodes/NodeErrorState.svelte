<script>
  let { children } = $props();
</script>

<div class="bg-red/20 text-red p-4 text-lg/6">
  {#if children}
    {@render children()}
  {:else}
    An unknown error occurred.
  {/if}
</div>
