<script>
  import '../app.css';

  import Sidebar from './Sidebar.svelte';

  let { children, data } = $props();
</script>

<svelte:head>
  <title>{data.site.title}</title>
</svelte:head>

<div class="bg-background text-text flex h-dvh overflow-hidden">
  <Sidebar />
  <main class="flex min-h-0 min-w-0 flex-1 flex-col overflow-y-auto">
    {@render children()}
  </main>
</div>
