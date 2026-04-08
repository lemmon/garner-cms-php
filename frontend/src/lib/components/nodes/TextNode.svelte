<script>
  import TextInput from '$lib/components/forms/TextInput.svelte';

  let { node, value = $bindable('') } = $props();
</script>

<div class="space-y-2">
  <TextInput
    label={node.label}
    placeholder={node.placeholder ?? ''}
    bind:value
  />

  {#if node.help}
    <p class="text-base/5 text-current/60">{node.help}</p>
  {/if}
</div>
