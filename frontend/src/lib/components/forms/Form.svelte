<script>
  import { api } from '$lib/api';
  import NodeErrorState from '$lib/components/nodes/NodeErrorState.svelte';

  let {
    class: classname = '',
    action = '',
    children,
    loading = $bindable(false),
    onsuccess,
    ...props
  } = $props();

  let errors = $state({});
  let error = $state(null);

  async function handleSubmit(event) {
    event.preventDefault();

    let form = event.currentTarget;
    let formData = new FormData(form);
    let payload = Object.fromEntries(formData.entries());

    loading = true;
    errors = {};
    error = null;

    try {
      let response = await api(action, payload);

      if (response.invalid) {
        errors = Object.fromEntries(
          (response.fields ?? []).map((field) => [field.path, field.message])
        );
        return;
      }

      await onsuccess?.(response);
    } catch (err) {
      error = err;
    } finally {
      loading = false;
    }
  }
</script>

<form
  class={['space-y-4', classname]}
  method="post"
  novalidate
  onsubmit={handleSubmit}
  {...props}
>
  {#if error}
    <NodeErrorState {error} />
  {/if}
  {@render children({ errors, loading })}
</form>
