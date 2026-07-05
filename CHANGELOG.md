# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Key-value store** — `$app->store()` gives actions and controllers
  durable site-wide storage: string keys, JSON values, backed by a single
  SQLite file (`storage/store.sqlite`, `app.store.path` config) created
  lazily on first write. This is the "keep something" half of the action
  layer — a notify-me form finally has somewhere framework-provided to put
  its email addresses instead of bring-your-own PDO. `add()` is the
  uniqueness primitive (atomic insert-if-absent, `false` when the key
  exists — no check-then-insert race between concurrent POSTs), `set()` is
  the upsert, and `get()`/`has()`/`remove()` match the `Session` surface
  exactly. `items(prefix)` lists a key namespace as an Illuminate
  Collection keyed by full key; `count(prefix)` answers "how many" without
  loading values. Multi-item data follows a one-key-per-item convention
  (`email:<hash>`), with key construction deliberately left in userland.
  The value contract is "JSON-encodable in, decoded value out" — objects
  come back as arrays by contract, non-encodable values throw, and
  whole-number floats are preserved on write
  (`JSON_PRESERVE_ZERO_FRACTION`). Unlike `runtime/index.sqlite` the store
  file is canonical, not rebuildable — back up `storage/` — and shared-host
  hardening matches the sessions feature: the file is kept owner-only
  (0600, re-asserted on each process's first write, so a pre-created or
  crash-orphaned file self-heals — and a write fails rather than proceed
  when the file cannot be tightened, e.g. a writable file another local
  user planted) and a created storage directory 0700, since store values
  are site data (possibly personal), and Garner refuses to open the store
  through a symlink — another local user must not be able to pre-create
  `store.sqlite` as a link and redirect writes to a path they chose. Reads treat a file whose schema doesn't exist yet (the
  brief window a concurrent first write opens, or an empty pre-created
  file) as "nothing stored" rather than erroring on the missing table.
  Deliberately
  a key-value store, not a database layer: no queries into values, no TTL,
  no multiple stores; a site that outgrows it brings its own PDO. Values
  sit as plain-TEXT JSON, inspectable via `sqlite3` or the new console
  commands `store:list [prefix] [--json]`, `store:get <key>`,
  `store:set <key> <json>`, `store:remove <key>`. Testable via
  `Application::withStore()` (same callback-scoped shape as
  `withSession()`). See `docs/key-value-store-next-steps.md`.

## [0.2.0] - 2026-07-05

### Added

- **Sessions** — `$app->session()` gives controllers and actions per-visitor
  key/value state: `get()`, `set()`, `remove()`, `flash()`/`consumeFlash()`
  (a value readable on the next request only, answering the
  Post/Redirect/Get flash-message gap), `regenerate()` (rotate the session
  id, e.g. after a future login feature authenticates someone), and
  `destroy()`. Activation is lazy and explicit: nothing is written and no
  cookie is sent until a request actually calls `set()`, `flash()`, or
  `destroy()`, so a plain content page stays exactly as stateless and
  cache-friendly as before. An incoming session cookie is only trusted when
  it names a session Garner itself issued (`SessionStore::exists()`); an
  unrecognized or tampered id is never adopted, preventing session
  fixation. Session ids come from a dedicated CSPRNG generator
  (`SecureRandomIdGenerator`, 128 bits via `random_bytes()`), deliberately
  independent of `app.ids.generator` — that setting scaffolds content ids
  and may be made predictable, but a session id is a bearer token.
  Persistence is pluggable via `app.session.store` (same shape as
  `app.ids.generator`); the built-in `FileSessionStore` keeps one file per
  session under `storage/sessions` — no extra dependency. The directory is
  created owner-only (0700) and files are chmod'd 0600 before becoming
  visible: session files hold per-visitor state and their names are the
  bearer-token ids, so other local users on a shared host must not see
  them. Writes go to a unique temp file renamed into place, so a
  concurrent read (or `session:gc`) always sees a complete file — never
  empty or half-written. Session files use
  PHP's `serialize()`/`unserialize()` rather than JSON: JSON carries known
  PHP round-trip hazards (whole-number float precision, objects silently
  decoding as plain arrays) that don't matter for hand-edited content but do
  for values stored blindly; `unserialize()` reads with
  `allowed_classes: false` to avoid PHP object-injection risk from a
  corrupted or tampered file. Sweep expired
  sessions with the new `php bin/garner session:gc` command. This is a
  generic primitive, not a "logged-in user" concept — see
  `docs/sessions-next-steps.md`.

- **Template fragments for htmx failure re-renders** —
  `ActionResult::failure()` accepts an optional `fragment` naming a Twig
  block in the page template. On an htmx POST (`HX-Request`), the failure
  answers with just that block — rendered with the same rebuilt context as
  the full re-render (read-side controller data plus `form`), same status —
  so the form swaps in place instead of receiving a whole page. Without a
  fragment (or for plain browser POSTs) the full-page re-render is
  unchanged. This is the htmx "template fragments" pattern: the fragment
  lives inside the page template it belongs to
  (`renderPageFragment()` on the renderer), no separate partial file. Note
  htmx does not swap 4xx responses out of the box — a site returning 422
  failures to htmx forms opts in via the documented `htmx-config` meta tag
  (see README).

- **Page actions (`+action.php`)** — a page's write-side POST handler, kept
  separate from the read-side `+controller.php`. The file returns a callable
  with the controller contract plus the request prepended —
  `(Request, Page, Site, Application)` — and produces either an
  `ActionResult` or a full `RenderedResponse` (JSON, fragments, custom
  headers — e.g. answering `isHtmx()` requests), and `form` is reserved for
  the action layer: `null` outside a failure re-render, with controller data
  unable to override it. `ActionResult` has two
  constructors: `failure(array $data, int $status = 422)` re-renders the page
  with the data available to the template as `form` — the re-render behaves
  exactly like the page's GET render plus `form`: read-side controllers see
  the request as a true GET (`Request::asGet()` via
  `Application::withRequest()`) with the submitted payload dropped (no form
  fields, files, body, or Content-Type), so controllers that branch on the
  method or build context from the request body contribute their normal
  context instead of reacting to the already-handled POST — and
  `redirect(string $location, int $status = 303)` answers Post/Redirect/Get
  (`RenderedResponse::redirect()` keeps its method-preserving 308 default for
  canonical redirects) and is htmx-aware: an htmx POST (`HX-Request`) gets
  `204` + `HX-Redirect` instead of a `3xx`, so htmx navigates the whole page
  rather than swapping the redirect target into the form's `hx-target`.
  Page dispatch is now method-aware: `form` is always
  defined in page render context (`null` on plain GET, so templates never
  depend on lax `strict_variables`), `HEAD` routes like `GET`, and a verb the
  page cannot answer returns `405 Method Not Allowed` with an `Allow` header
  (`GET, HEAD`, plus `POST` when an action exists). Compatibility is kept:
  before the 405, the page's controllers run — a controller that answers the
  verb with a `RenderedResponse` (pre-action POST branching) still wins — and
  route endpoints keep full method freedom. The origin-check CSRF default
  protects actions automatically. Prototyped end to end on the PHP Git Deploy
  splash's "notify me on release" form per
  `docs/form-actions-next-steps.md`.

- **Origin-check CSRF protection, on by default** — a POST carrying a form
  content type (`application/x-www-form-urlencoded`, `multipart/form-data`,
  `text/plain` — the three a cross-site HTML form can send without a CORS
  preflight) is rejected with a plain 403 when the browser-declared origin
  does not match the request's own origin. `Sec-Fetch-Site` is consulted
  first (`same-origin` / `none` pass, anything else rejects) — the browser
  computes it itself and it is scheme-independent, so it stays correct behind
  proxies that hide the original protocol. The `Origin` header (mismatched or
  `null` rejects) is the fallback, with one tolerance: an `https` origin
  matches an `http` base URL on the same host, covering TLS-terminating
  proxies that don't forward `X-Forwarded-Proto`. Stateless — no sessions or
  tokens — and enforced in the router, so pages, endpoints, and custom routes
  are covered alike. Requests with neither header (curl, webhook deliveries)
  pass: they carry no victim's browser credentials, and blocking them would
  break non-browser form posts — a softer default than SvelteKit's strict
  origin equality. JSON APIs are unaffected. Disable with
  `app.csrf.check_origin => false`.

- **Request helpers** — the `Request` facade grows the read surface the action
  layer needs: `header()` and `cookie()` (case-insensitive names, default
  fallback; a cookie a client sends in a non-scalar shape like `name[]=x`
  reads as absent rather than throwing — malformed client input, not an
  application error), `body()` (raw), `form()` (parsed form fields), `json()` (decoded
  body; empty array for an empty body, `JsonException` on malformed input),
  `file()` returning a Garner-styled `UploadedFile` (client-supplied name and
  MIME type flagged untrusted, `moveTo()` to accept the upload; validity and
  size are captured up front so they still describe the submission after the
  move), and `isHtmx()` for htmx's `HX-Request` marker. `Request::create()`
  accepts form parameters, cookies, files, and a raw body for tests. The
  request is reachable in controllers today via `$app->request()` — prefer it
  over PHP superglobals.

- **Response headers and cookies** — `RenderedResponse` gains `withHeader()` and
  `withCookie()` (immutable copies, chainable), with `header()` and `cookies()`
  accessors. Cookie defaults are the safe baseline: session lifetime, whole-site
  path, HttpOnly, SameSite=Lax. The response is backed by an HttpFoundation
  response internally (never exposed), and emission goes through a single
  `send()` path — the static `Garner\Core\Response` helper is gone. Existing
  constructors and accessors are unchanged, and headers stay verbatim:
  HttpFoundation's Cache-Control heuristics are disabled, so a response carries
  no Cache-Control unless one is set (setting ETag / Last-Modified / Expires
  does not add one), and an explicit value like `public, max-age=60` is sent
  as given instead of being rewritten with `private`.

### Changed

- **`Garner\Core\Request` is now instance-based** — a Garner-styled facade over
  `symfony/http-foundation` (new dependency) instead of six static helpers over
  superglobals, per the decision in `docs/form-actions-next-steps.md`. The
  instance lives on the application (`Application::request()`, built from
  globals on first use, injectable via the constructor for tests and custom
  boot), so request-dependent code is testable without mutating `$_SERVER`.
  The surface stays small and bare-accessor styled: `method()`, `path()`,
  `query()`, `basePath()`, `baseUrl()`, `isHttps()`, plus the `fromGlobals()` /
  `create()` factories. The unused `getInput()` / `getPayload()` helpers are
  gone; body and form accessors return with the action-layer work. At web root
  behavior is unchanged: the query string is preserved verbatim for canonical
  redirects, and scheme inference still honors `X-Forwarded-Proto`. Under a
  front-controller base path (a subdirectory install, or the script addressed
  directly), `path()` now yields the route path with the base stripped, and
  canonical redirects re-attach the base so they stay inside the app.

### Fixed

- **Symlinked package installs load the right autoloader** — `boot/web.php`
  now prefers `GARNER_PROJECT_ROOT` when locating `vendor/autoload.php`. PHP
  resolves `__DIR__` through symlinks, so with Garner installed as a symlinked
  Composer `path` repository the boot found Garner's own development `vendor/`
  and silently loaded it instead of the consumer project's, dropping the
  project's classes and dependencies. The constant is set by the entry script
  or derived from the document root, so it survives symlinks; the previous
  core-relative candidates remain as fallbacks for Garner's standalone use.
  The consumer `public/index.php` recipe is now documented in the README.

## [0.1.0] - 2026-07-03

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

[Unreleased]: https://github.com/lemmon/garner-cms-php/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/lemmon/garner-cms-php/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/lemmon/garner-cms-php/releases/tag/v0.1.0
