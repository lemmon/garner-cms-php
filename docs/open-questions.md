# Garner CMS Open Questions

This document tracks unresolved design questions that are not current bugs, but
should stay visible while the architecture evolves.

## Blueprint-Driven Studio Icons

Current state:

- Studio icons are imported explicitly in Svelte source
- the bundler only includes icons that are statically imported
- blueprints do not yet define icons for tabs, nodes, or sections

Why this is a question:

- blueprints should eventually be able to choose icons by name
- importing every icon from a package like `@lucide/svelte` would bloat the
  Studio bundle unnecessarily
- runtime string-based component lookup does not work automatically with the
  current static build model

Constraints:

- icon selection should be driven by blueprint data, not hardcoded per screen
- the Studio build should include only icons that Garner intentionally supports
- unsupported icon names should fail gracefully
- the solution should work for both core blueprints and project-level blueprint
  customizations

Current likely direction:

- let blueprints declare icon names as strings, for example `icon: files`
- keep a curated Studio icon registry in source code that maps those names to
  statically imported Svelte icon components
- resolve blueprint icon strings through that registry at runtime
- fall back safely when an icon name is unknown

Why this is the leading option:

- it keeps the bundle small
- it preserves static imports, which the build understands well
- it gives blueprints dynamic icon selection without requiring arbitrary module
  loading
- it keeps the frontend contract explicit and reviewable

Open follow-up:

- decide whether project-specific icons should be limited to the curated
  registry, or whether Garner should later support a custom project icon
  registry outside core

## Blueprint-Controlled Slug Editability

Current state:

- system pages (home, error) have non-editable slugs, determined by whether
  the page's id matches the site's `home_page_id` or `error_page_id`
- all other pages have freely editable slugs
- runtime "system page" identity is defined only by site config pointers
- the `home` and `error` blueprint/template names remain conventions for the
  configured system pages, but they do not themselves make a page system-like

Future direction:

- blueprints should be able to declare that a page's slug is equivalent to its
  id — meaning the slug is stored as `null` in the content file but is still
  used for routing (resolved to the page id at path-resolution time)
- when a blueprint declares this, the slug becomes non-editable regardless of
  system page status
- this makes slug editability a function of two inputs: system page status
  (site pointers) and blueprint schema configuration

Implication:

- `slug_editable` in the Studio API response already exists as a dedicated
  field, so the frontend does not need to derive this from `is_system`
- the backend can extend `slugEditableForPage` to also consult the blueprint
  definition when that schema property is introduced
- pages with `slug: null` and blueprint-locked slugs would resolve their
  public path segment from the page id, not from a stored slug string

## Multilingual Model Must Not Fork The Product

Current concern:

- multilingual support is intentionally not implemented yet
- however, the language model affects content shape, Studio behavior, and
  public rendering semantics deeply enough that it should not be treated as a
  late incidental feature

Why this matters:

- switching default language should not require painful content migration
- switching from single-language to multilingual should not feel like changing
  to a different CMS mode
- switching back from multilingual to single-language should also remain
  understandable and reversible
- phrase/translation management should not exist only for multilingual installs;
  even a single-language project should be able to choose its default language
  and manage UI/theme phrases cleanly

Current desired direction:

- treat single-language as a degenerate multilingual setup with exactly one
  active language
- keep one explicit default language concept from the start
- keep site phrases/translations available even when only one language is
  enabled
- avoid separate storage models for single-language and multilingual content
- avoid making language enablement a destructive migration boundary

Implication for future design:

- content storage, path derivation, Studio editing flows, and translation
  surfaces should be designed so that adding or removing languages changes
  configuration and content breadth, not the entire mental model of the CMS

Open follow-up:

- decide the canonical storage shape for translated page fields and translated
  phrases
- decide how URLs relate to default language versus non-default languages
- decide how much of the multilingual model should be present in v1, even if
  the full authoring UI lands later

## Path Mounting For Shared-Code Hosting

Current concern:

- Garner currently boots from one project root and derives `site/`, `content/`,
  `runtime/`, and `storage/` beneath it
- that is fine for a normal installed site, but a future host may want to share
  one code/theme/blueprint layer across many isolated site datasets

Why this matters:

- this style of hosting does not require Garner itself to become tenant-aware
- it only requires clean path boundaries
- if Garner bakes too much into one project-root assumption, later shared-code
  hosting becomes much harder than it needs to be

Current desired direction:

- keep Garner single-site in its own mental model
- later allow explicit configured path roots such as:
  - `site_path`
  - `content_path`
  - `runtime_path`
  - `storage_path`
  - `config_path`
- allow a future host layer to share `site/` while isolating content and runtime
  per mounted site or tenant

Constraint:

- this should not evolve into hidden multitenancy inside Garner core
- Garner should still behave as if it is running one site at a time

Open follow-up:

- decide whether path configuration should be all-or-nothing or individually
  overrideable
- decide when the current single-root API should be generalized in code

## Advanced Studio Editing UX

Current state:

- Studio page editing uses explicit manual save
- the save action lives in the page chrome, next to the public-page preview
- field controls remain interactive during save; only the save action shows
  loading state
- title/slug editing is already treated as a separate page-level affordance

Why this is a question:

- richer editing UX often grows into autosave, dirty-state indicators, revert,
  and page version history
- these features can improve comfort, but they also add a lot of behavioral
  complexity and can distort the content model if introduced too early

Current desired direction:

- keep explicit manual save as the default editing model
- avoid autosave until field coverage and persistence rules are broader
- avoid revert/version UI until revisions have a real backend model rather than
  client-side tricks
- if versions arrive later, build them on persisted content snapshots or
  revision records, not only on frontend state

Open follow-up:

- decide whether a lightweight dirty-state indicator is worth adding before
  full revision support
- decide what the canonical backend model for page revisions should be
- decide whether autosave, if ever added, should remain optional rather than
  replacing explicit save

## Blueprint Reserved Node Names

Current state:

- the page update endpoint treats `title` and `slug` as reserved payload keys
  with special validation and persistence behavior
- blueprint field nodes are validated separately from these reserved keys
- reserved page-level validators are applied after blueprint field validators so
  `title` and `slug` keep their dedicated validation behavior even if a
  blueprint uses those names
- nothing currently prevents a blueprint author from declaring a node named
  `title` or `slug`

Why this is a question:

- a blueprint node named `slug` would collide with the reserved slug key in the
  update payload, so the value would still be handled as a page-level slug
  change instead of a field update
- `title` is implicit and should never appear as a blueprint field node

Current desired direction:

- add validation in blueprint loading or in `BlueprintFieldNodes` that rejects
  nodes whose names collide with reserved keys
- the reserved set is currently `title` and `slug`; this may grow

## IntersectionObserver Lazy Loading for List Nodes

Current state:

- blueprint tab panels are rendered persistently (hidden when inactive) so
  saveable form inputs are always in the DOM
- non-saveable list nodes (file_list, page_list) are also rendered when their
  tab panel mounts, even if the tab is not visible
- list nodes currently fetch their query data on mount

Why this is a question:

- rendering all tab panels means every list node fetches on initial page load,
  even if the user never visits that tab
- for long single-tab pages with many list nodes, all queries fire at once

Current desired direction:

- use IntersectionObserver inside list node components to defer data fetching
  until the node enters the viewport
- this is orthogonal to the tab persistence model and works for both multi-tab
  and single-tab layouts
- individual node components own their own lazy loading; the parent layout does
  not need to track visited tabs
