# Garner CMS Architecture

## Purpose

This document is the current canonical technical model for Garner CMS.

Use it for:

- repository and installed-site layout
- content and storage rules
- request flow and rendering behavior
- extension points and operational boundaries

Use `vision.md` for product intent, `plan.md` for sequencing, and `decisions/` for the rationale behind key architectural choices.

## Runtime Parts

Garner has two main runtime parts:

- `backend/`: PHP bootstrap, router, APIs, content services, rendering, plugin loading
- `frontend/`: SvelteKit Garner Studio application

Garner Studio is served under `/studio`. Public APIs are served under `/api`.

## Repository Layout

Current core repository shape:

```text
backend/
  actions/
  config/
  index.php
  bootstrap.php
  src/
    Blueprint/
    Content/
    Core/
    Extensions/
    Site/
    Studio/
    Support/
bin/
frontend/
tests/
docs/
```

## Installed Site Layout

Target installed-site shape:

```text
index.php
public/
  index.php
content/
  +site.json
  pages/
    {id}/
      +page.json
      files/
site/
  favicon.ico
  routes.php
  blueprints/
  config/
  controllers/
  plugins/
  snippets/
  templates/
storage/
runtime/
  index.sqlite
vendor/
```

Installed-site notes:

- Garner should support either `index.php` at project root or `public/index.php` as the webroot entrypoint
- both entrypoints bootstrap Garner from `vendor/garner/cms/boot/web.php`
- the built Studio SPA is served from the installed package under `vendor/garner/cms/frontend/build`
- installed projects should not need their own `backend/` or `frontend/` directory

Directory responsibilities:

- `content/`: canonical content and page-owned media
- `site/`: developer-authored source only
- `storage/`: persistent app state
- `runtime/`: disposable derived state
- `vendor/`: Composer-installed code

Generated state must not be written into `site/`.

## Content Model

Canonical page files live at:

```text
content/pages/{id}/+page.json
```

Canonical site metadata lives at:

```text
content/+site.json
```

Important site fields:

- `id`: always `site`
- `title`: site title used by public and Studio surfaces
- `home_page_id`: required pointer to the logical home page
- `error_page_id`: optional pointer to the dedicated not-found page
- `updated_at`

Important page fields:

- `id`: stable generated identifier
- `kind`: initially `site` or `page`
- `parent_id`: nullable parent reference
- `slug`: nullable path segment
- `blueprint`: authoring schema name
- `template`: runtime template/controller name
- `status`: `draft`, `unlisted`, or `listed` for normal public pages; omitted for system pages such as `home` and `error`
- `sort`: nullable integer used only for listed pages
- `fields`: plain JSON object
- `created_at`
- `updated_at`

Conventions:

- persisted shapes use `snake_case`
- content-facing identifiers such as blueprint names, template names, controller names, and their filenames use `kebab-case`
- content is stored as pretty-printed JSON
- one entry equals one JSON file
- IDs come from a configurable generator, with `uuid_v7` as the default

## System Pages

Garner treats some pages as system pages referenced from `content/+site.json`.

Current model:

- `home_page_id` points to the required home page
- `error_page_id` may point to a dedicated not-found page

Current conventions:

- the home page uses the `home` blueprint and `home` template
- the error page uses the `error` blueprint and `error` template
- home and error pages do not persist a normal page `status`
- the home page resolves to `/`
- the error page is not part of the normal public tree

Runtime behavior:

- unresolved public requests first try the configured error page
- if no error page is configured or the page is missing, Twig falls back to the normal `404` template path

Studio and installer behavior are still expected to tighten around these system pages over time.

Current site and page traversal semantics:

- `Page.children(drafts: true)` returns direct children only
- `Page.index(drafts: true)` returns all descendants on all levels, excluding self
- `Site.children(drafts: true)` returns the editorial root slice: home plus home's direct children
- `Site.index(drafts: true)` returns the full public tree: home plus all of its descendants
- `Site.systemPages()` returns dedicated system pages outside the public tree, such as the error page
- sibling ordering is: listed pages first by `sort`, then unlisted pages by `slug` with `id` fallback
- when drafts are requested, drafts come last and are ordered by `slug` with `id` fallback
- unlisted pages do not persist a meaningful `sort`
- `Site.children()` and `Site.index()` still force home to the first position

Path derivation:

- the configured home page resolves to `/`
- otherwise use `slug` when present
- otherwise use `id` as the path segment

## Storage Model

Garner uses a hybrid storage model:

- JSON files are canonical
- SQLite is derived

The derived index lives at:

```text
runtime/index.sqlite
```

The SQLite index is responsible for:

- path lookup
- parent/child relationships
- status filtering
- ordered listings
- future search projection

The index can be rebuilt from files. It is derived state, not business data.

## File and Media Model

Files are page-owned by default:

```text
content/pages/{id}/files/
```

Rules:

- one page owns the file physically
- any page may reference that file logically
- ownership and accessibility are separate concerns
- cross-page file references must be safe on delete

Shared assets can be handled by a dedicated page in v1.

## Public Request Flow

Public request order:

1. Router receives the request path.
2. `/favicon.ico` serves `site/favicon.ico` when present, otherwise the built-in Garner fallback.
3. `/api/*` dispatches backend actions.
4. `/studio/*` serves the built Garner Studio SPA, including static assets and SPA fallback.
5. `site/routes.php` can return a direct response before page resolution.
6. Otherwise the path resolves through `runtime/index.sqlite`.
7. The canonical page JSON is loaded.
8. `site/controllers/{template}.php` runs if present.
9. If the controller returns an array, that data is merged into the Twig context.
10. If the controller returns `RenderedResponse`, Twig is skipped and that response is returned directly.
11. Otherwise `site/templates/{template}.twig` is rendered, with fallback to `default.twig`.

Key property:

- public routing does not depend on recursive filesystem traversal

## Bootstrapping

Garner core is installable as a Composer package.

Current model:

- core defaults live in the package under `backend/config`
- installed projects may override config with project-local `config/*.php`
- the application is bootstrapped with a distinct `corePath` and `projectRootPath`
- backend actions and built Studio assets resolve from the core package
- content, templates, blueprints, routes, runtime, and storage resolve from the installed project

This split is what allows a real installed project to have no local `backend/` or `frontend/` directory.

## Public URL Strategy

Garner should detect public origin and host automatically from the current
request in normal browser-driven production usage.

Target behavior:

- Studio and the public site usually live on the same host
- ordinary "open page" and similar in-app links should work without requiring
  explicit host configuration
- explicit URL configuration should remain available as an override and for
  features that require a canonical absolute URL outside the current request
  context

Current limitation:

- Garner still assumes root installation when composing public links

This should evolve into explicit application base-path support.

## Request Utilities

`backend/src/Core/Request.php` is the minimal backend request helper.

Current responsibilities:

- detect HTTPS from proxy/server headers
- read the current request path
- read raw input, including a CLI stdin fallback for test runners
- decode JSON payloads for Studio/API actions

## Error Handling

Garner uses a global backend error handler registered from `Application::run()`.

Current behavior:

- PHP warnings/notices are converted into exceptions
- uncaught API exceptions render JSON responses
- only uncaught `ValidationException` instances become `400` invalid responses with flattened field errors
- action-level payload validation is the only phase that should produce `invalid: true`
- `JsonException` and `InvalidArgumentException` become `400` API error responses with `error: true`
- service/runtime failures from `backend/src/` must not return `invalid: true`
- other uncaught API exceptions become generic `500` JSON responses
- non-API uncaught exceptions render a generic HTML `500` page in production
- when debug mode is enabled, HTML error pages use Symfony's error renderer when available and otherwise fall back to a verbose internal debug page
- exceptions are logged through `error_log()`

Debug behavior:

- `app.debug` is the primary debug switch
- if `APP_DEBUG` is not set, debug defaults to `true` on `localhost`, `127.0.0.1`, and `::1`
- if `APP_ENV` is not set, environment defaults to `development` when debug is on and `production` otherwise

## Twig Runtime

Garner uses Twig for public rendering and exposes a small Twig-specific config surface under `app.twig`.

Current defaults:

- `debug`: inherits `app.debug`
- `auto_reload`: inherits Twig debug mode
- `cache`: disabled in debug, otherwise defaults to `runtime/cache/twig`
- `strict_variables`: `false`

Supported Twig options today:

- `cache`
- `debug`
- `auto_reload`
- `strict_variables`
- `charset`

Relative Twig cache paths resolve from the installed project root, not from the core package path.

## Rendering Surfaces

Garner uses Twig for page templates.
Markdown is rendered through `league/commonmark` behind a Garner service and exposed to Twig as a `markdown` filter.

Main extension points:

- `site/routes.php`: explicit custom routes returning direct responses
- `site/controllers/{template}.php`: page-level pre-render logic
- `site/templates/{template}.twig`: page templates
- `site/snippets/*.twig`: shared partials
- `site/favicon.ico`: project favicon override

Controller contract:

- return `array<string, mixed>` to enrich Twig context
- return `RenderedResponse` to bypass Twig

This gives Garner both:

- route-style responses
- page-controller-style responses

without introducing a separate rendering mode.

## Blueprints and Runtime Semantics

Blueprints are authoring-time schema.

They are loaded from `site/blueprints/**/*.yml` through a Garner loader built on:

- `symfony/yaml` for parsing
- `lemmon/validator` for validation of the currently supported blueprint subset

They drive:

- Studio editing UI
- defaults
- write-time validation
- authoring structure

They do not define runtime truth.

Runtime code should:

- work on plain decoded JSON data
- use explicit helpers, factories, or DTOs when semantics are needed
- avoid universal coercion APIs

In the unified schema tree, node kinds stay explicit.

Examples:

- `text`, `textarea`, `pages`, `files`: input and picker nodes
- `page_list`, `file_list`: listing and manipulation nodes
- `tabs`, `group`: layout/container nodes

Current list-node semantics:

- `page_list.source` identifies the semantic source object for the listing
- the default page-list query is `source.children(drafts: true)`
- for `source: site`, that means the editorial root slice: home plus home's direct children
- the `source` object is also the default create target unless a future override says otherwise
- `file_list.source` identifies the semantic source/owner for file management

Top-level blueprint metadata may also describe the Studio screen itself.

Current example:

- `title`: required blueprint title
- `description`: optional Studio-facing intro copy for the screen that uses the blueprint
- `tabs`: ordered array of named tab objects, not an associative mapping
- site blueprint lives at `site/blueprints/site.yml`
- page blueprints live under `site/blueprints/pages/*.yml`
- reusable fragments such as shared tabs may live under paths like `site/blueprints/tabs/*.yml`
- blueprint mappings may use `extends: some/path` to reuse and override another blueprint fragment

This preserves the important distinction between a relation picker and an editorial listing without bringing back a separate fields-versus-sections model.

Current API surface:

- `/api/studio/site`: returns minimal site metadata for Studio shell use
- `/api/studio/blueprints/site`: returns the parsed site blueprint as JSON for Studio consumption
- `/api/studio/nodes/query`: resolves blueprint list-node queries from JSON payload
- `/api/studio/pages/show`: returns page detail plus the resolved blueprint payload when available

Current `nodes/query` payload contract:

- `type`: currently `page_list` is supported
- `source`: semantic source such as `site`, `site.home`, or `site.page("about-page")`
- `query`: optional override string; defaults to `source.children(drafts: true)`

Current supported page-list queries:

- `source.children(drafts: true)`
- `source.index(drafts: true)`
- `source.system_pages`

Current Studio page-detail behavior:

- page detail lives under `/studio/site/pages/[id]`
- the page list links directly to that nested Studio route
- the detail endpoint returns stored page metadata, raw `fields`, and the loaded blueprint when present
- Studio keeps page metadata loaded but does not display it yet
- page title is implicit for every page and is not declared as a blueprint node
- title and slug editing should use a separate page-level affordance, not blueprint field nodes
- the current detail screen renders supported field nodes from the loaded blueprint
- supported field nodes currently include `text` and `textarea`
- save/update behavior is not implemented yet
- missing page blueprints do not fail the entire detail view; they return `blueprint: null` with a `blueprint_issue`

Current validation boundary:

- validate blueprint files at load time
- validate Studio node-query payload shape at the action boundary with `lemmon/validator`
- resolve source/query semantics in the Studio service layer
- keep validation intentionally narrow to the node kinds currently supported by Studio
- preserve unknown blueprint keys in the returned payload instead of normalizing them away
- treat the current validation layer as intentionally incomplete, not as the final blueprint spec

## Studio and API

Garner Studio is a SvelteKit SPA under `/studio`.
In the source repository, the PHP backend serves the built app from `frontend/build/`.
In an installed project, the PHP runtime serves the built app from `vendor/garner/cms/frontend/build/`.
The Studio base path is currently fixed at build time and must match the backend `studio_prefix`.

Backend APIs are plain PHP actions under `backend/actions/`.

Initial Studio/backend responsibilities:

- authentication
- site metadata endpoint for Studio shell state
- blueprint fetch endpoints
- page tree and page detail endpoints
- validation through a Garner layer backed by `lemmon/validator`
- CRUD flows built on the same content services as the public runtime

## CLI

The CLI is part of Garner core.

Target surface:

- `bin/garner`
- core commands such as `reindex`, cache maintenance, runtime cleanup, and content operations

CLI commands should call the same core services used by HTTP and Studio flows.
The CLI should be the preferred automation surface for LLMs when available and
should stay mostly in parity with CMS capabilities.

## LLM Guide

Installed Garner projects should ship a root `llms.txt`.

Purpose:

- explain Garner's installed-project model to external LLMs
- describe safe assumptions about content, templates, blueprints, Studio, and CLI
- help LLMs build and operate Garner-powered sites

This file is not meant to be another maintainer-instructions file for Garner
core development.

## Dependency Policy

Garner should keep generic behavior in ecosystem libraries and CMS-specific behavior in Garner code.

Current direction:

- `twig/twig`: page rendering
- `league/commonmark`: Markdown parsing
- `illuminate/support`: collections and string helpers
- `symfony/yaml`: blueprint parsing
- `lemmon/validator`: validation engine behind a Garner adapter

Do not build Garner clones of generic utility libraries.

## Testing Priorities

Testing order:

- PHPUnit tests for content repository and path resolution
- integration tests for rendering, routes, controllers, and API actions
- performance tests for indexing and large content trees
- Studio end-to-end tests after core CRUD stabilizes
