# Repository Guidelines

## Project Purpose & Scope

- Build Garner CMS as a file-native PHP CMS with a Twig-rendered public site, a SvelteKit Studio SPA, and a first-class core CLI.
- Keep the core architecture coherent: JSON is canonical content, SQLite is a derived index, Studio talks to JSON APIs, and the CLI should stay mostly in parity with CMS capabilities.
- Optimize for small and medium PHP sites, shared-host-friendly installs, and low operational complexity. Do not over-engineer for distributed scale or heavy concurrency.
- Treat this repository as Garner core, not as an installed Garner project. The root [`llms.txt`](./llms.txt) is product-facing for external LLMs working on Garner-powered sites, not a maintainer file for this repo.

## Communication & Tone

- Be direct, factual, and pragmatic.
- Prefer clarity over flourish. Keep explanations concise unless depth is actually needed.
- Do not use cheerleading, hype, or reassuring filler.
- Challenge weak assumptions when necessary, but do it in a technical and constructive way.
- Keep the focus on architectural coherence, implementation quality, and next concrete steps.

## Project Structure & Module Organization

- `backend/` contains the PHP application. `backend/bootstrap.php` wires autoload and config. `backend/index.php` is the HTTP entry point. Core classes live under `backend/src/` with PSR-4 namespace `Garner\\`.
- `backend/actions/` contains action scripts mapped from `/api/*`. Actions must return a closure that accepts `Garner\Core\Application` and returns an array, a response object, or `null`. Keep actions thin and delegate to services in `backend/src/`.
- `frontend/` contains the SvelteKit Studio app. Studio is served at `/studio`, and the built output in `frontend/build/` is served by the PHP backend.
- `content/` contains canonical content fixtures for development. `site/` contains blueprints, templates, controllers, snippets, routes, and project-level source artifacts.
- `runtime/` is derived state. `storage/` is persistent app state. Never mix generated state into `site/`.
- `tests/` contains PHPUnit tests. Mirror backend features with focused unit/integration coverage.
- `docs/` contains architecture, plan, and design notes. `vision.md` is product intent, `architecture.md` is the current technical model, `plan.md` is sequencing, `known-issues.md` tracks accepted issues, and `open-questions.md` tracks unresolved design questions.

## Coding Style & Naming Conventions

- Follow PSR-12 with 4-space indentation for PHP. Target PHP 8.3+.
- Run Mago for formatting and linting. Use `composer format` and `composer lint`.
- Prefer trailing commas in multiline PHP arrays, argument lists, and object literals.
- Use named arguments when they improve clarity.
- Persisted and external data should use `snake_case`. Keep JSON output stable and pretty-printed.
- Action-boundary validation should use `lemmon/validator`. Validate route payloads in actions, not deep inside services.
- Validation phases are distinct:
  - action-boundary validation errors return `{invalid: true, fields}`
  - service/runtime failures return `{error: true, message}`
- Prefer exceptions to ad hoc local error handling. Let the global error handler map failures consistently.
- Use `Garner\Core\Request::getPayload()` for JSON request bodies.
- Page title is implicit and must not be modeled as a normal blueprint field.
- Blueprints are authoring-time schema, not runtime semantic truth.
- In Studio UI, avoid rounded corners.
- SvelteKit code should use Svelte 5 runes-style patterns and SPA mode via `export const ssr = false` where appropriate.

## Architecture Expectations

- Canonical content lives in `content/pages/{id}/+page.json` and `content/+site.json`.
- SQLite in `runtime/index.sqlite` is derived and rebuildable. Do not treat it as canonical business data.
- Public routing depends on indexed metadata, not directory nesting.
- System pages are explicit content:
  - home page is required and resolves to `/`
  - error page is optional and outside the normal public tree
- Traversal semantics currently follow:
  - `Page.children(drafts: true)` = direct children only
  - `Page.index(drafts: true)` = all descendants excluding self
  - `Site.children(drafts: true)` = home plus home's direct children
  - `Site.index(drafts: true)` = home plus all descendants
  - `Site.systemPages()` = dedicated system pages outside the public tree

## Studio & Frontend Conventions

- Studio lives at `/studio`. The current backend dev URL is `http://localhost:8030`.
- Use root-level JS tooling commands from the repo root. The actual Studio workspace package is `@garner/studio` in `frontend/`.
- Shared Studio UI primitives live in `frontend/src/lib/components/`.
- Shared Studio navigation metadata should live in a reusable module instead of being duplicated between sidebar, breadcrumbs, and other chrome.
- Prefer blueprint-driven rendering for Studio screens. If a component is a generic blueprint or node primitive, keep it reusable across site/page/system screens.
- Avoid pulling arbitrary runtime icon packs into the Studio build. Prefer curated static registries and document open questions before inventing dynamic loading schemes.

## Development Workflow

- Install dependencies with:
  - `composer install`
  - `composer studio:install`
- Start the backend with `composer start` on `http://localhost:8030`.
- Start Studio dev with `composer studio:dev`, typically at `http://localhost:5173/studio`.
- Build Studio with `composer studio:build`.
- Use the backend-served Studio at `http://localhost:8030/studio` when testing integrated behavior.

## Testing & Tooling

- Run PHPUnit with `composer test`.
- Run PHPStan with `composer analyze`.
- Run the full PHP check suite with `composer check`.
- Run frontend/root formatting and linting with:
  - `npm run format`
  - `npm run lint`
- Prefer adding or updating tests when changing:
  - traversal semantics
  - path/index behavior
  - action validation/error boundaries
  - Studio API contracts
  - blueprint parsing and validation

## Documentation & Process

- Keep docs aligned with architecture changes. Update `docs/architecture.md` when the technical contract changes, `docs/plan.md` when sequencing changes, `docs/known-issues.md` for accepted shortcomings, and `docs/open-questions.md` for unresolved design directions.
- Preserve the distinction between:
  - `AGENTS.md`: maintainer guidance for this repository
  - `llms.txt`: installed-project guidance for external LLMs using Garner
- If a change affects how external LLMs should work with Garner-powered sites, update `llms.txt`.
- If a design problem is real but not ready for implementation, document it before improvising a partial framework around it.
