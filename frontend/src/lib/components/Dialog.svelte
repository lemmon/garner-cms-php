<script>
  import { twMerge } from 'tailwind-merge';

  let {
    class: classname,
    open = $bindable(false),
    preventEscape = false,
    onclose,
    title,
    children,
    ...props
  } = $props();

  const uid = $props.id();
  const titleId = `dialog-title-${uid}`;

  function handleClose() {
    open = false;
    onclose?.();
  }

  function handleNativeClose(e) {
    if (!open) return;
    if (preventEscape) {
      if (e.target.isConnected) e.target.showModal();
      return;
    }
    open = false;
    onclose?.();
  }

  function syncDialog(el) {
    if (open) el.showModal();
    else el.close();
    return () => el.close();
  }
</script>

<dialog
  aria-label={title ? undefined : 'Dialog'}
  aria-labelledby={title ? titleId : undefined}
  aria-modal="true"
  class="fixed inset-0 m-0 max-h-none max-w-none border-0 bg-transparent p-0 backdrop:bg-black/80"
  {@attach syncDialog}
  oncancel={(e) => preventEscape && e.preventDefault()}
  onclose={handleNativeClose}
  {...props}
>
  <div
    class="fixed inset-0 flex items-center justify-center p-6"
    role="presentation"
  >
    {#if !preventEscape}
      <button
        type="button"
        tabindex="-1"
        class="absolute inset-0 -z-10 cursor-default"
        aria-label="Close dialog"
        onclick={handleClose}
      ></button>
    {/if}
    <div
      class={twMerge(
        'relative z-0 max-h-[90vh] w-full max-w-2xl overflow-auto',
        'bg-white',
        'space-y-8 p-10',
        classname
      )}
      role="document"
    >
      {#if title}
        <h2
          id={titleId}
          class="text-5xl font-medium tracking-tight text-balance"
        >
          {title}
        </h2>
      {/if}
      {@render children({ handleClose })}
    </div>
  </div>
</dialog>
