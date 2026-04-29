<script>
  import PencilLineIcon from '@lucide/svelte/icons/pencil-line';
  import WandSparklesIcon from '@lucide/svelte/icons/wand-sparkles';

  import { invalidate } from '$app/navigation';
  import Button from '$lib/components/Button.svelte';
  import Dialog from '$lib/components/Dialog.svelte';
  import Form from '$lib/components/forms/Form.svelte';
  import TextInput from '$lib/components/forms/TextInput.svelte';
  import { generateSlug } from '$lib/slug';

  let {
    title = '',
    slug = '',
    action = '',
    id = '',
    invalidateKeys = [],
    slugEditable = false,
    editLabel = 'Edit title and slug',
  } = $props();

  let dialogOpen = $state(false);
  let dialogLoading = $state(false);
  let draftTitle = $state('');
  let draftSlug = $state('');
  let slugAuto = $state(true);
  let normalizedSlug = $derived(typeof slug === 'string' ? slug : '');

  let accessibleEditLabel = $derived(
    title ? `${editLabel} for ${title}` : editLabel
  );

  function openDialog() {
    let generatedSlug = generateSlug(title);

    draftTitle = title;
    draftSlug = normalizedSlug || generatedSlug;
    slugAuto = normalizedSlug === '';
    dialogOpen = true;
  }

  function handleTitleInput(event) {
    if (!slugEditable) return;

    if (slugAuto) {
      draftSlug = generateSlug(event.currentTarget.value);
    }
  }

  function handleSlugInput(event) {
    slugAuto = event.currentTarget.value.trim() === '';
  }

  function handleSlugGenerate() {
    draftSlug = generateSlug(draftTitle);
    slugAuto = true;
  }
</script>

<h1 class="text-5xl font-medium tracking-tight text-balance">
  <button
    type="button"
    class="group flex cursor-pointer flex-row items-center gap-1 outline-none"
    aria-label={accessibleEditLabel}
    aria-haspopup="dialog"
    title={editLabel}
    onclick={openDialog}
  >
    {title}
    <span
      class="group-focus:outline-blue invisible flex p-3 text-neutral-500 group-hover:visible group-focus:visible group-focus:outline-2 group-focus:-outline-offset-2"
    >
      <PencilLineIcon aria-hidden="true" />
    </span>
  </button>
</h1>

{#if dialogOpen}
  <Dialog
    open
    preventEscape={dialogLoading}
    onclose={() => {
      dialogOpen = false;
    }}
    class="max-w-2xl"
  >
    {#snippet children({ handleClose })}
      <Form
        {action}
        onsuccess={async () => {
          await Promise.all(invalidateKeys.map((key) => invalidate(key)));
          handleClose();
        }}
        bind:loading={dialogLoading}
      >
        {#snippet children({ loading, errors })}
          {#if id}
            <input type="hidden" name="id" value={id} />
          {/if}
          <TextInput
            label="Title"
            name="title"
            bind:value={draftTitle}
            error={errors.title}
            required
            autofocus
            oninput={handleTitleInput}
          />
          {#if slugEditable}
            <TextInput
              label="Slug"
              name="slug"
              bind:value={draftSlug}
              error={errors.slug}
              required
              oninput={handleSlugInput}
            >
              {#snippet actions()}
                <Button
                  class="-my-0.5 px-1.5 py-0.5 text-lg/6"
                  type="button"
                  kind="secondary"
                  onclick={handleSlugGenerate}
                >
                  <WandSparklesIcon size={16} aria-hidden="true" />
                  Generate
                </Button>
              {/snippet}
            </TextInput>
          {/if}
          <div class="mt-8 flex flex-row flex-wrap gap-2">
            <Button class="min-w-40" type="submit" {loading}>Update</Button>
            <Button
              class="min-w-32"
              type="button"
              onclick={handleClose}
              kind="secondary"
              disabled={loading}
            >
              Cancel
            </Button>
          </div>
        {/snippet}
      </Form>
    {/snippet}
  </Dialog>
{/if}
