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
bin/
  garner
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

Important page fields:

- `id`: stable generated identifier
- `kind`: initially `site` or `page`
- `parent_id`: nullable parent reference
- `slug`: nullable path segment
- `blueprint`: authoring schema name
- `template`: runtime template/controller name
- `status`: `draft`, `unlisted`, or `listed`
- `sort`: nullable integer
- `fields`: plain JSON object
- `created_at`
- `updated_at`

Conventions:

- persisted shapes use `snake_case`
- content is stored as pretty-printed JSON
- one entry equals one JSON file
- IDs come from a configurable generator, with `uuid_v7` as the default

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

## Studio and API

Garner Studio is a SvelteKit SPA under `/studio`.
In the source repository, the PHP backend serves the built app from `frontend/build/`.
The Studio base path is currently fixed at build time and must match the backend `studio_prefix`.

Backend APIs are plain PHP actions under `backend/actions/`.

Initial Studio/backend responsibilities:

- authentication
- page tree and page detail endpoints
- validation through a Garner layer backed by `lemmon/validator`
- CRUD flows built on the same content services as the public runtime

## CLI

The CLI is part of Garner core.

Target surface:

- `bin/garner`
- core commands such as `reindex`, cache maintenance, and runtime cleanup

CLI commands should call the same core services used by HTTP and Studio flows.

## Dependency Policy

Garner should keep generic behavior in ecosystem libraries and CMS-specific behavior in Garner code.

Current direction:

- `twig/twig`: page rendering
- `league/commonmark`: Markdown parsing
- `illuminate/support`: collections and string helpers
- `lemmon/validator`: validation engine behind a Garner adapter

Do not build Garner clones of generic utility libraries.

## Testing Priorities

Testing order:

- PHPUnit tests for content repository and path resolution
- integration tests for rendering, routes, controllers, and API actions
- performance tests for indexing and large content trees
- Studio end-to-end tests after core CRUD stabilizes
