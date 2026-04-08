<script>
  import TextArea from '$lib/components/forms/TextArea.svelte';

  let { node, value = '' } = $props();
</script>

<div class="space-y-2">
  <TextArea
    label={node.label}
    name={node.name}
    placeholder={node.placeholder ?? ''}
    rows={node.rows ?? 8}
    {value}
  />

  {#if node.help}
    <p class="text-base/5 text-current/60">{node.help}</p>
  {/if}
</div>
