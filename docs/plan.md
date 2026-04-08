# Garner CMS Implementation Plan

## Scope Discipline

The project should not begin as "Kirby replacement."

It should begin as:

- a Twig renderer
- a JSON content engine
- a SQLite path index
- a SvelteKit Studio
- a cleaner plugin and package model

That is enough to validate the architecture.

## Decisions To Freeze Early

Freeze these before writing much code:

- canonical content format is JSON
- entry identity uses a configurable generator, with `uuid_v7` as the default
- URL paths are derived from parent relationships and indexed in SQLite
- Twig is the default public-site renderer
- Garner Studio lives at `/studio`
- SvelteKit is Studio-only
- blueprints use YAML with a unified schema tree
- blueprints are authoring-time schema, not runtime truth
- runtime transformations use explicit typed helpers/factories
- generic collection/string behavior comes from ecosystem libraries, not Garner clones
- `lemmon/validator` is the validation engine behind a thin Garner adapter
- `site/` remains source-only
- `storage/` is persistent and `runtime/` is disposable
- Composer plugins are discovered from `vendor/` without mirrored directories
- the CLI ships as part of Garner core, not as a separate companion package

If those decisions remain fluid for too long, the Studio and template APIs will be built on unstable assumptions.

## Phase 1: Project Skeleton

Deliver:

- `backend/` bootstrap and router
- initial core CLI entrypoint shape
- `frontend/` SvelteKit shell
- installed-site directory conventions for `content/`, `site/`, `storage/`, and `runtime/`
- dependency policy for native PHP versus adopted utility packages
- Composer dependency choice for `lemmon/validator`
- Composer and npm manifests
- dev scripts for backend and frontend
- test harness

Exit criteria:

- backend serves a health endpoint
- Studio shell runs in dev
- repository layout is stable enough for further work

## Phase 2: Content Engine

Deliver:

- JSON entry model
- ID generator abstraction plus `uuid_v7` default implementation
- filesystem store using `content/pages/{uuid}/+page.json`
- site metadata in `content/+site.json`, including home and error system page pointers
- SQLite index builder writing to `runtime/index.sqlite`
- repository/query interfaces
- adopted collection/string utility dependencies

Exit criteria:

- create a small content tree in files
- rebuild SQLite index from files
- resolve `/`, `/about`, and nested paths without filesystem traversal

## Phase 3: Site Rendering

Deliver:

- custom route layer for non-Twig responses
- page controllers that can either enrich Twig context or short-circuit with a response
- `favicon.ico` override plus a built-in fallback asset
- CommonMark-based Markdown rendering exposed to Twig through a Garner filter
- template resolution
- Twig integration
- renderer abstraction
- typed value helpers/factories for runtime code
- collection-based traversal/query pipelines where generic operations are needed
- snippet support
- page/site objects
- 404 handling
- basic config loading

Exit criteria:

- a minimal site renders from JSON content and Twig templates
- route-style and page-controller-style responses both work
- path lookup goes through the index
- rendering does not require loading blueprints at request time
- template code does not depend on physical content file locations

## Phase 4: Blueprint System

Groundwork already exists:

- YAML blueprint parsing via `symfony/yaml`
- initial site blueprint at `site/blueprints/site.yml`
- initial `home` and `error` system blueprints
- load-time validation of the current supported blueprint subset via `lemmon/validator`
- first Studio blueprint endpoint at `/api/studio/blueprints/site`

Deliver:

- YAML blueprint loader
- unified schema node parser
- validation rules derived from blueprint definitions
- blueprint-to-`lemmon/validator` rule compilation through a Garner adapter
- blueprint-to-Studio schema transformation

Exit criteria:

- a blueprint can define content fields and sidebar-like collections in one schema tree
- semantic list nodes such as `page_list.source` and `file_list.source` are supported
- backend can validate content payloads against blueprint rules
- validation is powered by `lemmon/validator` without leaking raw library usage throughout the codebase
- blueprints drive authoring and validation without being required as runtime semantic truth

## Phase 5: Studio Foundation

Deliver:

- authentication with persistent account storage
- runtime session storage
- page tree endpoint
- page detail endpoint and initial blueprint-driven edit shell
- API request validation using the Garner validation layer
- SvelteKit Studio navigation and edit view shell

Exit criteria:

- log in to Studio
- browse page tree
- open a page and inspect blueprint-driven field inputs before save is introduced

## Phase 6: Editing and Media

Deliver:

- create/update/delete page
- move page
- slug updates with path reindex
- page-owned file uploads
- cross-page file references by file UUID
- reference-aware file deletion or reassignment flow
- optimistic locking or conflict detection

Exit criteria:

- edit content in Studio
- publish changes to JSON files
- keep SQLite index in sync
- move pages without content file relocations
- preserve cross-page file links safely

## Phase 7: Extensions and Hardening

Deliver:

- plugin discovery from `site/plugins` and Composer metadata
- hooks/events
- custom field node registration
- core CLI maintenance commands such as `reindex`
- performance fixtures

Exit criteria:

- local and Composer plugins both load cleanly
- core CLI commands are available without installing a separate package
- no mirrored plugin directories are needed
- large fixture sites remain responsive

## Recommended First Proof

Before building much Studio UI, prove this slice end-to-end:

1. one JSON home page
2. one nested child page
3. SQLite index generation
4. public Twig rendering by resolved URL
5. one API endpoint that updates a page and refreshes the index

If that slice feels clean, the rest of the system has a solid foundation.

## Things To Defer On Purpose

Defer these until the core model is proven:

- multilingual content
- multisite
- revision browser
- block editor complexity
- broad Kirby import compatibility
- alternate database backends
- extensive Studio customization API

## My Strongest Technical Recommendation

Do not build Studio first.

Studio is downstream of:

- content identity
- storage format
- path derivation
- blueprint structure

If those are unstable, Studio work becomes expensive rework. The content engine and render pipeline should set the rules first.
