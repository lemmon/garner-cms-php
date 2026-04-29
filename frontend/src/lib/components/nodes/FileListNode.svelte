<script>
  import NodeEmptyState from '$lib/components/nodes/NodeEmptyState.svelte';
  import NodeSection from '$lib/components/nodes/NodeSection.svelte';

  let { node } = $props();
</script>

<NodeSection class="not-last:mb-12" label={node.label} help={node.help}>
  <NodeEmptyState text={node.empty || 'No files yet.'} />
</NodeSection>
