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
