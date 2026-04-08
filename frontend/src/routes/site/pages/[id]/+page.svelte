<script>
  import BlueprintView from '$lib/components/BlueprintView.svelte';

  let { data } = $props();
</script>

<svelte:head>
  <title>{data.page.title} | {data.site.title}</title>
</svelte:head>

<BlueprintView
  title={data.page.title}
  description={data.blueprint?.description}
  blueprint={data.blueprint}
  blueprintIssue={data.blueprintIssue}
  fields={data.page.fields}
/>
