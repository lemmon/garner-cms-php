<script>
  import NodeEmptyState from '$lib/components/nodes/NodeEmptyState.svelte';
  import NodeSection from '$lib/components/nodes/NodeSection.svelte';

  let { node } = $props();
</script>

<NodeSection label={node.label} meta={node.type} help={node.help}>
  <NodeEmptyState text={node.empty || 'No files yet.'} />
</NodeSection>
