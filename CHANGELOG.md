# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

Initial implementation of the agent-first, flat-file CMS.

### Added

- **Filesystem routing** — the directory tree under `routes/` defines the route
  tree (`routes/+page.json` → `/`, `routes/blog/post/+page.json` → `/blog/post`).
  Directories without an entry file are non-routable containers whose children
  still route.
- **`+page.json` entry contract** — the only constrained file, with no required
  fields; optional `id`, `template`, `draft`, `sort`, and `created` are
  shape-validated when present (via `lemmon/validator`), and all other keys are
  kept as freeform metadata. `+page.yaml` / `+page.yml` are accepted alternatives.
- **Derived SQLite route index** — a rebuildable cache at `runtime/index.sqlite`
  (the files stay canonical). Freshness mirrors Twig: rescans on change in
  development, trusts the built index in production; configurable via
  `app.index.mode`. Built atomically with a fingerprint check.
- **Identifier generation** — CUID2 by default, with `ulid`, `uuid_v4`,
  `uuid_v7`, a callable, or a custom generator class also supported. An explicit
  `id` wins, otherwise it is inherited from the directory name; global uniqueness
  is enforced at index-build time.
- **Draft / published model** — a `draft` boolean is the single core publication
  state; drafts return 404 and are excluded from listings. Finer visibility is
  left to freeform fields filtered with the query API.
- **Query API via Laravel Collections** — traversal returns a `PageCollection`
  (extending `illuminate/collections`) with the full collection API plus
  `published()` / `drafts()`. Listings exclude drafts by default and are ordered
  by `sort` then path; `children(drafts: true)` includes drafts.
- **Traversal and references** — `site.home`, `site.children`, `site.index`,
  `page.children`, `page.index`, and `findById()` to resolve a stable id to its
  current page (surviving moves).
- **Rendering** — Twig templates resolved by the page's `template` field (falling
  back to `default`), Markdown through `league/commonmark` exposed as a
  `markdown` filter, and a `dump()` extension. Co-located `+template.twig` (a
  page's own view, able to extend shared templates) and `+controller.php`
  (returns context data to merge, or a `RenderedResponse` to bypass Twig — e.g.
  JSON) override the template-based defaults.
- **Error handling** — status-aware error rendering with optional `error.twig` /
  `404.twig` templates and a built-in generic fallback; verbose debug pages in
  development.
- **Project layout** — `routes/` (the page tree), `app/` (developer templates,
  controllers, `routes.php` custom routes, favicon), `config/`, `public/` (web
  root), `runtime/` (disposable cache), and `storage/` (persistent state).
- **CLI** (`bin/garner`, built on `symfony/console`) — `reindex` (rebuild the
  index), `validate` (read-only whole-tree integrity check; `--json`), and
  `page:create` (scaffold a page directory and `+page.json`; `--title`,
  `--template`, `--draft`, `--dry-run`, `--json`).
- **Tooling** — a single `composer check` gate running Mago (format + lint),
  PHPStan (level 7), and PHPUnit.

[Unreleased]: https://github.com/lemmon/garner-cms-php/commits/main
