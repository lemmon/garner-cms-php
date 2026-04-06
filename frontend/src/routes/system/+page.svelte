<script>
  let { data } = $props();
</script>

<svelte:head>
  <title>System | {data.site.title}</title>
</svelte:head>

<div class="space-y-12 p-12">
  <header class="space-y-3">
    <h1 class="text-4xl font-medium tracking-tight text-balance">System</h1>
    <p class="max-w-2xl text-lg/6 text-current/60">
      Environment details, caches, indexes, and maintenance actions will live
      here once the backend operational surface is in place.
    </p>
  </header>
</div>
