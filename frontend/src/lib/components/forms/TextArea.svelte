<script>
  import { twMerge } from 'tailwind-merge';

  import Label from '$lib/components/forms/Label.svelte';

  let {
    class: classname,
    label,
    disabled,
    error,
    required,
    name,
    value = $bindable(''),
    rows,
    placeholder,
    ...props
  } = $props();

  const uid = $props.id();
  const inputId = `form-input-${uid}`;
  const errorId = `form-input-error-${uid}`;

  let mirrorValue = $derived(
    (value ?? '') === '' ? '\u200b' : `${value ?? ''}\n`
  );
</script>

<div class={twMerge('space-y-2', classname)}>
  {#if label}
    <Label for={inputId} {disabled}>{label}</Label>
  {/if}
  <div class={['relative', 'p-4 text-lg/6']}>
    <textarea
      class={[
        'w-full resize-none bg-neutral-100 p-[inherit] font-[inherit]',
        'absolute inset-0',
        'outline -outline-offset-1 outline-neutral-500/50',
        'focus:outline-blue focus:outline-2 focus:-outline-offset-2',
        'aria-invalid:not-focus:outline-red',
        'disabled:cursor-not-allowed disabled:text-current/20 disabled:outline-neutral-500/10',
      ]}
      id={inputId}
      {name}
      {placeholder}
      rows={rows ?? undefined}
      aria-invalid={error ? 'true' : undefined}
      aria-describedby={error ? errorId : undefined}
      aria-required={required ? 'true' : undefined}
      {disabled}
      bind:value
      {...props}
    ></textarea>
    <div
      aria-hidden="true"
      class={[
        'pointer-events-none invisible break-normal wrap-break-word whitespace-pre-wrap',
        'min-h-[3lh]',
      ]}
    >
      {mirrorValue}
    </div>
  </div>
  {#if error}
    <p id={errorId} class="text-red text-xl/6" role="alert">{error}</p>
  {/if}
</div>
