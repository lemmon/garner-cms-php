<script>
  import BlueprintView from '$lib/components/BlueprintView.svelte';

  let { data } = $props();
</script>

<svelte:head>
  <title>Site | {data.site.title}</title>
</svelte:head>

<BlueprintView
  title={data.site.title}
  description={data.blueprint.description}
  breadcrumbs={[{ label: 'Site' }]}
  blueprint={data.blueprint}
/>
