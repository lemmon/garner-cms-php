<script>
  import TextInput from '$lib/components/forms/TextInput.svelte';

  let { node, value = '' } = $props();
</script>

<div class="space-y-2">
  <TextInput
    label={node.label}
    name={node.name}
    placeholder={node.placeholder ?? ''}
    {value}
  />

  {#if node.help}
    <p class="text-base/5 text-current/60">{node.help}</p>
  {/if}
</div>
