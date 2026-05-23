<script>
  import { api } from '$lib/api';
  import Button from '$lib/components/Button.svelte';
  import Dialog from '$lib/components/Dialog.svelte';

  let {
    item,
    siblings = [],
    onsaved,
    kind = 'ghost',
    class: classname = 'py-border-4 text-lg/6 underline decoration-current/20',
  } = $props();
  const uid = $props.id();

  let dialogOpen = $state(false);
  let loading = $state(false);
  let error = $state(null);
  let errors = $state({});
  let draftStatus = $state('draft');
  let draftPosition = $state('1');

  const statusOptions = [
    {
      value: 'draft',
      label: 'Draft',
      info: 'Hidden from the public site and kept in Studio.',
    },
    {
      value: 'unlisted',
      label: 'Unlisted',
      info: 'Published at its URL without appearing in pages lists.',
    },
    {
      value: 'listed',
      label: 'Public',
      info: 'Published and ordered with sibling pages.',
    },
  ];

  let statusText = $derived(labelForStatus(item.status));
  let listedSiblings = $derived(
    siblings
      .filter(
        (candidate) =>
          candidate.id !== item.id &&
          candidate.parent_id === item.parent_id &&
          candidate.status === 'listed'
      )
      .sort(compareItems)
  );
  let listedPeers = $derived(
    siblings
      .filter(
        (candidate) =>
          candidate.parent_id === item.parent_id &&
          candidate.status === 'listed'
      )
      .sort(compareItems)
  );
  let positionOptions = $derived(
    Array.from({ length: listedSiblings.length + 1 }, (_, index) => ({
      position: index + 1,
      before: listedSiblings[index] ?? null,
    }))
  );
  let currentPosition = $derived(
    item.status === 'listed'
      ? Math.max(
          1,
          listedPeers.findIndex((candidate) => candidate.id === item.id) + 1
        )
      : positionOptions.length
  );

  function labelForStatus(status) {
    return (
      statusOptions.find((option) => option.value === status)?.label ?? status
    );
  }

  function compareItems(left, right) {
    return (
      sortValue(left) - sortValue(right) ||
      slugValue(left).localeCompare(slugValue(right)) ||
      idValue(left).localeCompare(idValue(right))
    );
  }

  function sortValue(page) {
    return typeof page.sort === 'number' ? page.sort : Number.MAX_SAFE_INTEGER;
  }

  function slugValue(page) {
    return typeof page.slug === 'string' ? page.slug : '';
  }

  function idValue(page) {
    return typeof page.id === 'string' ? page.id : '';
  }

  function openDialog() {
    draftStatus = item.status || 'draft';
    draftPosition = String(currentPosition);
    error = null;
    errors = {};
    dialogOpen = true;
  }

  function handleStatusChange() {
    errors = {};

    if (draftStatus === 'listed' && String(draftPosition).trim() === '') {
      draftPosition = String(currentPosition);
    }
  }

  async function saveStatus(handleClose) {
    let payload = {
      id: item.id,
      status: draftStatus,
    };

    if (draftStatus === 'listed') {
      payload.position = Number.parseInt(String(draftPosition), 10);
    }

    loading = true;
    error = null;
    errors = {};

    try {
      let response = await api('studio/pages/update', payload);

      if (response.invalid) {
        errors = Object.fromEntries(
          (response.fields ?? []).map((field) => [field.path, field.message])
        );
        return;
      }

      handleClose();
      await onsaved?.(response);
    } catch (err) {
      error = err;
    } finally {
      loading = false;
    }
  }
</script>

<Button
  type="button"
  {kind}
  class={classname}
  aria-haspopup="dialog"
  onclick={openDialog}
>
  {statusText}
</Button>

{#if dialogOpen}
  <Dialog
    open
    preventEscape={loading}
    onclose={() => {
      dialogOpen = false;
    }}
    class="max-w-xl"
  >
    {#snippet children({ handleClose })}
      <div class="space-y-4">
        <fieldset class="space-y-2">
          <legend class="text-lg/6 font-medium">Select status</legend>
          <div
            class="bg-neutral-100 p-2 outline -outline-offset-1 outline-neutral-500/50"
          >
            {#each statusOptions as option, index (option.value)}
              <label
                class="has-focus:outline-blue grid cursor-pointer grid-cols-[auto_1fr] gap-x-2 p-2 has-focus:outline-2"
                for={`${uid}-status-${index}`}
              >
                <input
                  class="accent-blue mt-1 size-4 outline-none"
                  id={`${uid}-status-${index}`}
                  name={`${uid}-status`}
                  type="radio"
                  value={option.value}
                  bind:group={draftStatus}
                  disabled={loading}
                  onchange={handleStatusChange}
                />
                <span class="space-y-1 py-0.5">
                  <span class="block text-lg/5">{option.label}</span>
                  <span class="block text-base/5 text-current/50">
                    {option.info}
                  </span>
                </span>
              </label>
            {/each}
          </div>
        </fieldset>

        {#if errors.status}
          <p class="text-red text-xl/6" role="alert">{errors.status}</p>
        {/if}

        {#if draftStatus === 'listed'}
          <label class="block space-y-2">
            <span class="block text-lg/6 font-medium">Position</span>
            <select
              class="focus:outline-blue w-full bg-neutral-100 p-4 text-lg/6 outline -outline-offset-1 outline-neutral-500/50 focus:outline-2 focus:-outline-offset-2 disabled:cursor-not-allowed disabled:text-current/20 disabled:outline-neutral-500/10"
              bind:value={draftPosition}
              disabled={loading}
              required
            >
              {#each positionOptions as option (option.position)}
                <option value={String(option.position)}>
                  {option.position}
                </option>
                {#if option.before}
                  <option disabled value={`page-${option.before.id}`}>
                    {option.before.title}
                  </option>
                {/if}
              {/each}
            </select>
          </label>

          {#if errors.position || errors.sort}
            <p class="text-red text-xl/6" role="alert">
              {errors.position || errors.sort}
            </p>
          {/if}
        {/if}

        {#if error}
          <p class="text-red text-xl/6" role="alert">
            {error.message || 'Unable to update page status.'}
          </p>
        {/if}

        <div class="mt-8 flex flex-row flex-wrap gap-2">
          <Button
            class="min-w-40"
            type="button"
            {loading}
            onclick={() => saveStatus(handleClose)}
          >
            Update
          </Button>
          <Button
            class="min-w-32"
            type="button"
            kind="secondary"
            disabled={loading}
            onclick={handleClose}
          >
            Cancel
          </Button>
        </div>
      </div>
    {/snippet}
  </Dialog>
{/if}
