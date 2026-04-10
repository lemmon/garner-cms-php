<script>
  let { error, children } = $props();
</script>

<div class="bg-red/20 text-red p-4 text-lg/6">
  {#if children}
    {@render children({ error })}
  {:else if error?.body?.message}
    {error.body.message}
  {:else if error?.message}
    {error.message}
  {:else}
    An error occurred while processing the request
  {/if}
</div>
