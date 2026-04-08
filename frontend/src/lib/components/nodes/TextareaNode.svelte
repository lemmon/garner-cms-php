<script>
  import TextArea from '$lib/components/forms/TextArea.svelte';

  let { node, value = $bindable('') } = $props();
</script>

<div class="space-y-2">
  <TextArea
    label={node.label}
    placeholder={node.placeholder ?? ''}
    rows={node.rows ?? 8}
    bind:value
  />

  {#if node.help}
    <p class="text-base/5 text-current/60">{node.help}</p>
  {/if}
</div>
