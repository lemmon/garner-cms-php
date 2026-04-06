<script>
  import Label from '$lib/components/forms/Label.svelte';

  let {
    class: classname = '',
    disabled = false,
    error = '',
    label = '',
    optional = false,
    required = false,
    rows = 6,
    value = $bindable(''),
    ...props
  } = $props();

  const uid = $props.id();
  const inputId = `form-input-${uid}`;
  const errorId = `form-input-error-${uid}`;
</script>

<div class={['space-y-2', classname]}>
  {#if label}
    <Label for={inputId} {disabled} {optional}>{label}</Label>
  {/if}

  <textarea
    class={[
      'w-full border border-neutral-900/12 bg-white/80 px-5 py-4 text-base/6 transition-colors outline-none',
      'focus:border-blue focus:ring-blue/20 focus:ring-2',
      error ? 'border-red-500/70 ring-2 ring-red-500/10' : '',
      disabled ? 'cursor-not-allowed text-current/40' : '',
      'resize-y',
    ]}
    id={inputId}
    aria-describedby={error ? errorId : undefined}
    aria-invalid={error ? 'true' : undefined}
    aria-required={required ? 'true' : undefined}
    bind:value
    {disabled}
    {rows}
    {...props}
  ></textarea>

  {#if error}
    <p id={errorId} class="text-sm/5 text-red-600" role="alert">{error}</p>
  {/if}
</div>
