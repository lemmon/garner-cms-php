<script>
  let { data } = $props();
</script>

<svelte:head>
  <title>Users | {data.site.title}</title>
</svelte:head>

<div class="space-y-12 p-12">
  <header class="space-y-3">
    <h1 class="text-4xl font-medium tracking-tight text-balance">Users</h1>
    <p class="max-w-2xl text-lg/6 text-current/60">
      Studio authentication and user management will land here. This route is
      intentionally present now so the navigation contract is stable.
    </p>
  </header>
</div>
