<script>
  import PlusIcon from '@lucide/svelte/icons/plus';
  import WandSparklesIcon from '@lucide/svelte/icons/wand-sparkles';

  import { goto } from '$app/navigation';
  import { resolve } from '$app/paths';
  import Button from '$lib/components/Button.svelte';
  import Dialog from '$lib/components/Dialog.svelte';
  import Form from '$lib/components/forms/Form.svelte';
  import TextInput from '$lib/components/forms/TextInput.svelte';
  import { generateSlug } from '$lib/slug';

  let { source = '' } = $props();

  let dialogOpen = $state(false);
  let dialogLoading = $state(false);
  let draftTitle = $state('');
  let draftSlug = $state('');
  let slugAuto = $state(true);

  function openDialog() {
    draftTitle = '';
    draftSlug = '';
    slugAuto = true;
    dialogOpen = true;
  }

  function handleTitleInput(event) {
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

<Button
  class="-my-1 px-1.5 py-1 text-lg/6"
  type="button"
  kind="secondary"
  aria-haspopup="dialog"
  onclick={openDialog}
>
  <PlusIcon size={16} aria-hidden="true" />
  Create Page
</Button>

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
        action="studio/pages/create"
        onsuccess={async (response) => {
          handleClose();

          if (
            typeof response?.page?.id === 'string' &&
            response.page.id !== ''
          ) {
            await goto(resolve(`/site/pages/${response.page.id}`));
          }
        }}
        bind:loading={dialogLoading}
      >
        {#snippet children({ loading, errors })}
          <input type="hidden" name="source" value={source} />
          <TextInput
            label="Title"
            name="title"
            bind:value={draftTitle}
            error={errors.title}
            required
            autofocus
            oninput={handleTitleInput}
          />
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
          <div class="mt-8 flex flex-row flex-wrap gap-2">
            <Button class="min-w-40" type="submit" {loading}>Create</Button>
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
