<script>
  import { twMerge } from 'tailwind-merge';

  import Label from '$lib/components/forms/Label.svelte';

  let {
    class: classname,
    label,
    disabled,
    error,
    optional,
    required,
    name,
    value = $bindable(''),
    placeholder,
    ...props
  } = $props();

  const uid = $props.id();
  const inputId = `form-input-${uid}`;
  const errorId = `form-input-error-${uid}`;
</script>

<div class={twMerge('space-y-2', classname)}>
  {#if label}
    <Label for={inputId} {disabled} {optional}>{label}</Label>
  {/if}
  <input
    type="text"
    class={twMerge(
      'w-full bg-neutral-100 p-4 text-lg/6',
      'outline -outline-offset-1 outline-neutral-500/50',
      'focus:outline-blue focus:outline-2 focus:-outline-offset-2',
      'aria-invalid:not-focus:outline-red',
      'disabled:cursor-not-allowed disabled:text-current/20 disabled:outline-neutral-500/10'
    )}
    id={inputId}
    {name}
    {placeholder}
    bind:value
    aria-invalid={error ? 'true' : undefined}
    aria-describedby={error ? errorId : undefined}
    aria-required={required ? 'true' : undefined}
    {disabled}
    {...props}
  />
  {#if error}
    <p id={errorId} class="text-red text-xl/6" role="alert">{error}</p>
  {/if}
</div>
