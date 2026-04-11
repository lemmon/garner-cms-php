# Garner CMS Known Issues

This document tracks known issues that are accepted for now but should remain visible.

## Studio SPA Prefix Is Hardcoded

Current state:

- the Studio frontend build hardcodes `base: '/studio'` in [frontend/svelte.config.js](../frontend/svelte.config.js)
- the backend still exposes `app.routes.studio_prefix` in [backend/config/app.php](../backend/config/app.php)
- the PHP Studio serving layer assumes those two values match

Why this matters:

- the system currently behaves correctly only while the Studio prefix stays `/studio`
- changing only the backend prefix would break built Studio asset URLs and SPA routing

Current decision:

- accept `/studio` as the fixed Studio prefix for now
- keep the backend config value aligned with that fixed prefix

Future fix options:

- make the Studio prefix non-configurable and treat `/studio` as a hard contract
- or generate the frontend base path from the same source as the backend prefix during the Studio build

## Non-Root Installation Is Not Supported Yet

Current state:

- Garner now detects the current public base URL automatically from the request by default
- `app.url` remains available as an explicit override when needed
- Studio currently builds public page links by concatenating `site_url + page.path`

Why this matters:

- the current implementation still assumes Garner is installed at the web root
- installing Garner under a subdirectory such as `/cms` or `/project/public` would produce incorrect public links

Current decision:

- automatic request-based URL detection should be the default production behavior
- explicit URL configuration should remain available as an override and for features that truly need a canonical absolute URL outside the current request context
- accept the current root-location-only support as temporary

Future fix options:

- derive public URLs from a normalized request-aware `{origin, base_path}` contract rather than simple string concatenation
- support non-root installs explicitly by modeling application base path
- keep canonical URL configuration as an override for proxies, multiple domains, CLI flows, feeds, emails, and other non-request contexts

## Blueprint Validation Is Intentionally Incomplete

Current state:

- blueprint files are parsed from `site/blueprints/**/*.yml`
- the backend validates only the currently supported blueprint subset
- unknown keys are preserved in the returned blueprint payload
- full schema validation and blueprint-to-content validation are not implemented yet

Why this matters:

- a blueprint can still be structurally valid for the current loader while containing unsupported future nodes
- Studio can safely consume the current blueprint contract, but the validation layer is not a complete blueprint spec yet

Current decision:

- keep blueprint validation narrow while the blueprint model is still settling
- expand validation together with real Studio features instead of freezing a large schema too early

Future fix options:

- introduce a fuller node registry and validate all supported node kinds centrally
- compile blueprint definitions into content-payload validators for write endpoints
- add stronger validation around queries, container nodes, and future picker/listing variants

## System Pages Are Not Auto-Provisioned Yet

Current state:

- `content/+site.json` may point to dedicated home and error pages
- public runtime knows how to use those pages when they exist
- the project does not automatically recreate them when they are missing

Why this matters:

- a broken or incomplete install can be left without a configured home page or error page
- runtime still degrades safely for 404 handling, but Studio and install flows do not yet repair the content model automatically

Current decision:

- keep system pages as explicit content, not hidden virtual pages
- avoid mutating content automatically during normal public requests

Future fix options:

- create home and error pages during install or first-run setup
- add a repair command or Studio action for missing system pages
- tighten system-page invariants in write flows once page editing exists

## Studio Node Query Supports Page Lists Only

Current state:

- `/api/studio/nodes/query` exists
- it currently supports `page_list` sources and queries
- `file_list` is not implemented yet

Why this matters:

- the endpoint shape is intentionally generic, but only the page side is wired today
- Studio file-list nodes still need their own source/query behavior behind the same endpoint contract

Current decision:

- keep one generic node-query endpoint
- add file-list support later rather than splitting into unrelated page/file API patterns

Future fix options:

- implement `file_list` source resolution for `site` and page-owned files
- add shared node-query validation so unsupported node types fail more explicitly

## Node help text and validation errors can show simultaneously

`TextNode` and `TextareaNode` render blueprint `node.help` as a trailing paragraph
below the input, while `TextInput` and `TextArea` render validation errors in their
own trailing paragraph. When both are present, the field shows two messages stacked
beneath it — one red error and one grey help — which is redundant and noisy.

Future fix:

- move help text rendering into `TextInput` and `TextArea` so they own the full
  below-input slot
- show error _instead of_ help when a validation error is active, not both
- this also collapses `TextNode`/`TextareaNode` into thin wrappers that only map
  blueprint node shape to form component props
