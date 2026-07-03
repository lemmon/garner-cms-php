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
- **Canonical paths** — route paths have no trailing slash (root `/` excepted).
  Slash variants of a routable path (`/about/`, `/about////`) answer with a
  permanent redirect (308, query string preserved) to the canonical form instead
  of serving duplicate content; non-routable paths 404 without redirecting.
- **Route endpoints** — a directory with a `+controller.php` but no entry file is
  a routable endpoint: it dispatches its controller (the same
  `(page, site, app)` contract) but carries no metadata and is excluded from the
  page tree (`site.index`, `children`, `findById`). Ideal for `sitemap.txt`,
  feeds, and JSON APIs that should not appear as content pages.
- **`+page.json` entry contract** — the only constrained file, with no required
  fields; optional `id`, `template`, `draft`, `sort`, and `created` are
  shape-validated when present (via `lemmon/validator`), and all other keys are
  kept as freeform metadata. `+page.yaml` / `+page.yml` are accepted alternatives.
- **Derived SQLite route index** — a rebuildable cache at `runtime/index.sqlite`
  (the files stay canonical). Freshness mirrors Twig: rescans on change in
  development, trusts the built index in production; configurable via
  `app.index.mode`. Built atomically with a content fingerprint check, plus a
  `schema_version` marker so an index built under an older Garner version
  self-heals (rebuilds) on the next request in either mode, instead of failing
  once the code and the stored schema disagree. See `docs/index-freshness.md`.
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
- **URLs and paths** — one rule across the API: `url()` is an absolute URL,
  `path()` is a route path. `site.url()` returns the site base URL
  (`scheme://host`, no trailing slash), inferred from the request and overridable
  with the `app.url` config / `APP_URL` env; resolved once per request by
  `Application::siteUrl()`. `page.url()` returns the page's full URL (base URL +
  route path) — ready for hrefs, sitemaps, `og:url`, and canonical links;
  `page.path()` is the bare route path (the page's routing identity).
- **Files & media** — non-content files beside a page entry are page-owned assets,
  reached with `page.file('photo.jpg')` and `page.files()` (a `FileCollection` with
  an `images()` filter). Optional sidecars (`photo.jpg.json`) carry metadata and are
  never created automatically. `file.url()` publishes the file into the gitignored
  `public/media/<hash>/` directory and returns a content-hashed, immutable URL; the
  web server then serves it directly. See `docs/media-handling.md` for the design and
  the deferred questions (cache/proxy interaction, garbage collection, loose/site
  assets, private streaming, thumbnails).
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
  index), `cache:clear` (delete the compiled Twig template cache; a production
  deploy runs it together with `reindex` to refresh both derived caches),
  `validate` (read-only whole-tree integrity check; `--json`), and
  `page:create` (scaffold a page directory and `+page.json`; `--title`,
  `--template`, `--draft`, `--dry-run`, `--json`).
- **Site-wide extension hooks** — `app/controllers/site.php` provides shared
  template context for every rendered page (same `(page, site, app)` contract;
  must return an array; page-controller keys win on conflict), and `app/twig.php`
  extends the Twig environment (returns a callable
  `(Environment, Application): void` registering functions, filters, or globals)
  for render-time computation templates own — e.g. values derived from
  block-overridable titles.
- **Dotenv** — an optional `.env` in the project root populates `$_ENV` before
  config loads (via `symfony/dotenv`, wired into the shared boot factory so web
  and CLI behave identically). The Symfony cascade applies (`.env`, `.env.local`,
  `.env.{APP_ENV}`, `.env.{APP_ENV}.local`) and real environment variables always
  win over file values. Convention: gitignore `.env`, commit a `.env.example`.
- **Tooling** — a single `composer check` gate running Mago (format + lint),
  PHPStan (level 7), and PHPUnit.

[Unreleased]: https://github.com/lemmon/garner-cms-php/commits/main
