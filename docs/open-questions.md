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
