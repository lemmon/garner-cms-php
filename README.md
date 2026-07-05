# Garner

An agent-first, flat-file PHP CMS. Content lives as plain files on disk, the
directory tree defines the routes, and pages render through Twig. There is no
proprietary content format to learn — humans and AI agents edit the same files.

## Requirements

- PHP 8.4+ with `pdo_sqlite`
- Composer

## Quick start

```sh
composer install
composer start          # serves http://localhost:8040
```

Add a page by creating a directory with a `+page.json` entry:

```sh
routes/
└── hello/
    ├── +page.json
    └── main.md
```

```json
{ "title": "Hello" }
```

That answers `/hello`. `main.md` is exposed to the template as `content.main`.

## Project layout

```
app/       templates, controllers, routes.php, favicon
config/    configuration (config/app.php)
public/    web root — point the document root here (public/index.php)
routes/    the page tree (one directory per route)
runtime/   derived index + caches (disposable, rebuildable)
storage/   persistent app state
```

## Using Garner as a package

A site can require Garner as a Composer dependency and keep only its own
content and configuration (`routes/`, `app/`, `config/`, `public/`). The web
entry point is a two-liner — `public/index.php`:

```php
<?php

declare(strict_types=1);

define('GARNER_PROJECT_ROOT', dirname(__DIR__));

require dirname(__DIR__) . '/vendor/lemmon/garner/boot/web.php';
```

`GARNER_PROJECT_ROOT` declares where the site lives — the directory holding
`routes/`, `app/`, and `config/`. The boot cannot reliably infer it on its
own: it runs before the autoloader exists, server variables vary across
SAPIs, and Garner's own file location is misleading once it is a vendor
package — under a symlinked Composer `path` repository, PHP resolves
`__DIR__` to the real checkout. The constant also tells the boot which
`vendor/autoload.php` to load: the project's, never Garner's own development
install.

For local development, serve through the bundled router script — it sets
`GARNER_PROJECT_ROOT` from the document root, serves published media and
other static files directly, and hands everything else to Garner:

```sh
php -S localhost:8000 -t public vendor/lemmon/garner/boot/server.php
```

## How a page works

A page is a directory under `routes/`. Its route is its path: `routes/+page.json`
→ `/`, `routes/blog/post/+page.json` → `/blog/post`. A directory without an entry
file is a non-routable container; its children still route.

Route paths are canonical without a trailing slash (the root `/` being the only
exception). A request that differs from a routable path only by slashes (`/about/`,
`/about////`) gets a permanent redirect (308, query string preserved) to the
canonical form, so the same content is never served at more than one URL. Paths
whose canonical form doesn't route just 404.

A directory that has a `+controller.php` but no entry file is a **route endpoint**:
it routes and dispatches its controller (the usual `(page, site, app)` contract,
returning a `RenderedResponse`), but carries no metadata and is excluded from the
page tree — it never appears in `site.index`, `children`, or `findById`. Use it for
`sitemap.txt`, feeds, and JSON APIs that should not be treated as content pages.

### The `+page.json` contract

`+page.json` is the only file Garner constrains, and it has no required fields — a
directory with a `+page.json` (even `{}`) is a page. Any keys are kept as freeform
metadata.

| Field      | Default            | Notes                                      |
| ---------- | ------------------ | ------------------------------------------ |
| `id`       | the directory name | Any unique string; explicit value wins.    |
| `template` | `default`          | Twig template / controller name.           |
| `draft`    | `false`            | When `true`, the page 404s and is hidden.  |
| `sort`     | `0`                | Integer; lower comes first in listings.    |
| `created`  | none               | Non-empty string (timestamp) when present. |

YAML is accepted as an alternative entry file (`+page.yaml` / `+page.yml`).

### Content files

Any recognized file beside the entry becomes a named value on `content`, keyed by
its basename:

- `.md`, `.markdown`, `.txt` → string (render with the `markdown` filter)
- `.json`, `.yaml`, `.yml` → decoded array

So `main.md` → `content.main`, `data.json` → `content.data`. Files beginning with
`+` or `.` are reserved and never loaded as content.

### Files and media

Any _other_ file beside a page (an image, PDF, video, download) is a file asset owned
by that page. Reach them from the page:

```twig
{% set photo = page.file('team.jpg') %}
{% if photo %}<img src="{{ photo.url }}" alt="{{ photo.get('alt') }}">{% endif %}

{% for image in page.files.images %}
  <img src="{{ image.url }}">
{% endfor %}
```

`file.url()` publishes the file into the gitignored `public/media/<hash>/` directory
(a content hash, so the URL is immutable and cache-busts on edit) and the web server
serves it directly. Publishing makes a file publicly downloadable — keep private files
out of `url()` and stream them through a controller instead.

Metadata is optional and lives in a sibling sidecar, never created automatically:

```text
routes/about/team.jpg          a file asset
routes/about/team.jpg.json     { "alt": "The team", "credit": "Jane" }
```

The sidecar attaches to the file (`page.file('team.jpg').meta`) and is not loaded as a
content value. See [`docs/media-handling.md`](docs/media-handling.md) for the full
design and open questions.

### Co-located template, controller, and action

Three optional `+` files let a page override its view and behavior:

- **`+template.twig`** — the page's own Twig view. It can `{% extends %}` /
  `{% include %}` anything in `app/templates`. Overrides the `template` field.
- **`+controller.php`** — returns an array (merged into the template context) or a
  `RenderedResponse` (bypasses Twig — e.g. JSON). Overrides the template-based
  `app/controllers/{template}.php`.
- **`+action.php`** — the page's POST handler, kept separate from the read-side
  controller. See [Form actions](#form-actions).

```php
<?php // routes/api/+controller.php

use Garner\Render\RenderedResponse;

return static fn($page, $site, $app) => RenderedResponse::json(['ok' => true]);
```

A `RenderedResponse` is immutable; `withHeader()` and `withCookie()` return
modified copies for extra response headers and cookies:

```php
return static fn($page, $site, $app) => RenderedResponse::json(['ok' => true])
    ->withHeader('X-Robots-Tag', 'noindex')
    ->withCookie('seen', '1');
```

## Drafts and visibility

Garner has exactly one publication state in core: `draft`. A draft (`"draft": true`)
404s publicly and is excluded from listings; everything else is published.

| State     | Resolves at URL | In listings |
| --------- | --------------- | ----------- |
| published | yes             | yes         |
| `draft`   | no (404)        | no          |

Finer visibility — "in the footer but not the header", "featured", "archived" — is
a listing decision, not a global page property, so it lives in your own freeform
fields and is filtered with the collection API (see [Traversal](#traversal)):

```twig
{# hide pages that opt out with a "nav": false field #}
{% for child in site.children.reject(child => child.get('nav') is same as false) %}
```

## Ordering

Listings order by `sort`, then route path. `sort` defaults to `0`, so negatives
pin to the top, positives sink below the defaults, and unset pages sort by path.

## Traversal

Available in templates and via the `Garner\Content\Pages` repository:

- `site.home` — the `/` page
- `site.children` — home plus its direct children
- `site.index` — home plus all descendants
- `page.children` — direct children
- `page.index` — all descendants

Listings exclude drafts and are ordered by `sort` then path. Each returns a
`Garner\Content\PageCollection` (a [Laravel collection](https://laravel.com/docs/collections)
of `Page`), so the full query API is available — `filter`, `reject`, `where`,
`sortBy`, `first`, `take`, plus `published()` and `drafts()`:

```twig
{% for post in page.children.sortBy('created').reverse.take(5) %}
```

To include drafts (e.g. a preview build), pass `drafts: true`:
`page.children(drafts=true)`.

## References

Reference another page by its **stable id** and resolve it at render time, so
moving a page never breaks the link:

- `site.findById(id)` — the page with that id (routable pages only), or null

```twig
{% set author = site.findById(page.meta.author_id) %}
{% if author %}<a href="{{ author.url }}">{{ author.title }}</a>{% endif %}
```

How you _store_ a reference (a `+page.json` field, a value in a content file) is up
to you — Garner only resolves the id to its current page.

## Routing index

Routes resolve through a derived SQLite index at `runtime/index.sqlite`. The files
are canonical; the index is a rebuildable cache. Its freshness mirrors Twig:

- **development** — rescans the tree and rebuilds when content changes
- **production** — uses the built index as-is (rebuild it on deploy)

This follows `app.debug` by default; override with `app.index.mode` (`scan` /
`locked`). Rebuild manually:

```sh
php bin/garner reindex
```

Compiled Twig templates are cached the same way (`runtime/cache/twig`, never
recompiled in production), so a deploy must refresh both derived caches or keep
serving stale pages:

```sh
php bin/garner cache:clear && php bin/garner reindex
```

For the freshness model in depth — the two kinds of staleness, per-environment
guidance, and the schema-version auto-heal that recovers from engine upgrades — see
[`docs/index-freshness.md`](docs/index-freshness.md).

## Rendering

Twig templates live in `app/templates/`, resolved by the page's `template` field
(falling back to `default`). Markdown is rendered through `league/commonmark`,
exposed as a `markdown` Twig filter:

```twig
<h1>{{ page.title }}</h1>
{{ content.main|markdown }}
```

### Controllers

Data for a template comes from up to two controllers, both with the same
`(page, site, app)` contract:

- **The page's own controller** — a co-located `+controller.php`, or
  `app/controllers/{template}.php` for its template. May return an array of
  context, or a `RenderedResponse` to bypass rendering entirely.
- **`app/controllers/site.php`** — shared context, run for every rendered page.
  Must return an array (it provides data, not responses). Page controller keys
  win on conflict. The name `site` is reserved for this role.

### Form actions

A co-located `+action.php` handles the page's POST — the write side, kept
separate from the controller's read side. It returns a callable with the
controller contract plus the request prepended:

```php
<?php // routes/subscribe/+action.php

use Garner\Render\ActionResult;

return static function ($request, $page, $site, $app): ActionResult {
    $email = trim((string) ($request->form()['email'] ?? ''));

    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return ActionResult::failure([
            'error' => 'Enter a valid email address.',
            'email' => $email,
        ]);
    }

    // ... persist the subscription ...

    return ActionResult::redirect('/subscribe/thanks');
};
```

- **Failure** — `ActionResult::failure($data, $status = 422, $fragment = null)`
  re-renders the page with `$data` available to the template as `form`. The
  `form` variable is always defined in page render context: `null` outside a
  failure re-render (controller data cannot override it), so templates can
  branch on it without lax `strict_variables`. The re-render behaves exactly
  like the page's GET render plus `form`: read-side controllers see the
  request as a true GET — the submitted payload (form fields, files, body) is
  dropped — so one that branches on the method or reads the request body
  contributes its normal context instead of reacting to the POST the action
  already handled. `$fragment` names a Twig block in the page template: an
  htmx POST is then answered with just that block (same context, same status)
  so the form swaps in place instead of receiving a whole page — see below.
- **Success** — `ActionResult::redirect($location, $status = 303)` answers
  Post/Redirect/Get (303 makes the client re-request the target with GET;
  `RenderedResponse::redirect()` keeps its method-preserving 308 default for
  canonical redirects). htmx-aware: an htmx POST (`HX-Request`) gets `204` +
  `HX-Redirect` instead of a `3xx` — htmx follows redirects inside its XHR
  and would swap the target page into the form's `hx-target`, while
  `HX-Redirect` makes it navigate the whole page, which is what an action
  redirect means.
- **Escape hatch** — return a full `RenderedResponse` for JSON, fragments
  (e.g. when `$request->isHtmx()`), or custom headers.

For htmx forms, wrap the form in a named block and point the failure at it —
the fragment lives inside the page template it belongs to, no separate
partial file:

```twig
{% block subscribe_form %}
  <form hx-post="{{ page.path }}" hx-swap="outerHTML">
    {% if form is not null %}<p class="error">{{ form.error }}</p>{% endif %}
    <input type="email" name="email" value="{{ form.email ?? '' }}">
    <button>Subscribe</button>
  </form>
{% endblock %}
```

```php
return ActionResult::failure(['error' => '…'], fragment: 'subscribe_form');
```

The fragment block renders alone: `{% set %}` statements elsewhere in the
template do not run, so keep the block self-contained — derived values
belong in the controller (its data is part of the fragment context) or
inside the block itself. Under `strict_variables` an outside dependency
throws; under Twig's lax default it silently renders empty. A template that
cannot meet this should skip `fragment:` and let the form pluck its piece
from the full re-render with [`hx-select`](https://htmx.org/attributes/hx-select/).

One htmx default to know about: htmx does not swap `4xx` responses out of
the box, so a `422` failure would be silently ignored. Opt the site in with
htmx's own configuration mechanism, e.g.:

```html
<meta
  name="htmx-config"
  content='{"responseHandling": [
  {"code":"422", "swap": true},
  {"code":"204", "swap": false},
  {"code":"[23]..", "swap": true},
  {"code":"[45]..", "swap": false, "error":true},
  {"code":"...", "swap": false}
]}'
/>
```

Page dispatch is method-aware: `HEAD` routes like `GET`, POST goes to the
action, and a verb the page cannot answer returns `405 Method Not Allowed`
with an `Allow` header. A page controller may still answer any verb with a
`RenderedResponse` (method branching predating actions keeps working), and
route endpoints keep full method freedom. Cross-site form POSTs are already
rejected by the origin check before an action runs.

### Twig extensions

`app/twig.php` extends the Twig environment: it returns a callable
`(Environment $twig, Application $app): void` that registers functions, filters,
or globals. Use it for render-time computation that belongs in templates — e.g.
values derived from a title that child templates override via blocks, which no
controller can know ahead of rendering:

```php
return static function (Environment $twig, Application $app): void {
    $twig->addFunction(new TwigFunction('og_image', /* ... */));
};
```

## Sessions

`$app->session()` gives a controller or action per-visitor key/value state —
`get()`, `set()`, `has()`, `remove()`, plus `flash()`/`consumeFlash()`
(and `hasFlash()` to peek without consuming) for a value that survives
exactly one redirect (the Post/Redirect/Get flash-message case). One key
is reserved: `_flash` carries flash metadata internally, and `set('_flash', ...)`
throws rather than letting the value be silently lost:

```php
// routes/subscribe/+action.php
$app->session()->flash('notice', 'Subscribed!');

return ActionResult::redirect('/subscribe/thanks');
```

```php
// routes/subscribe/thanks/+controller.php
return static fn(Request $request, Page $page, Site $site, Application $app): array => [
    'notice' => $app->session()->consumeFlash('notice'),
];
```

```twig
{# routes/subscribe/thanks/+template.twig #}
{% if notice %}<p>{{ notice }}</p>{% endif %}
```

Activation is lazy: for a visitor with no session, reading or never touching
the session costs nothing and sends no cookie, so a plain content page stays
exactly as stateless and cache-friendly as it would be without this feature
at all. A cookie is only set once a request calls `set()`, `flash()`, or
`destroy()`. A flashed value survives exactly one request whether or not it
is consumed — the load that makes it readable also expires it. An
incoming session cookie is only trusted when it names a session Garner
itself issued — an unrecognized or tampered value is never adopted, so a
client can't plant a session id (session fixation). Call `regenerate()` the
moment a session's privilege changes (e.g. right after a login feature
authenticates someone). Session ids always come from a dedicated
cryptographically random generator, independent of `ids.generator` — that
setting shapes scaffolded content ids and may be made predictable, but a
session id is a bearer token and must stay unguessable.

Data persists through a pluggable `SessionStore` — `FileSessionStore` by
default, one file per session under `storage/sessions`, no extra dependency
required. The directory and its files are owner-only (0700/0600 — session
files hold per-visitor state and their names are the session ids), and each
write lands atomically via a temp-file rename, so concurrent requests never
see a half-written session. Session files use PHP's `serialize()`, not JSON: nobody hand-edits
them, so Garner's file-legibility bias (which is about content meant for
human editing) doesn't apply, and `serialize()` avoids known JSON round-trip
hazards for values stored blindly (whole-number float precision, objects
silently decoding as plain arrays). Sweep expired sessions with
`php bin/garner session:gc`
(wire it into a deploy hook or cron, the same way `reindex` is). This is a
generic primitive, not a "logged-in user" concept — a future auth feature
would store a user id in it rather than inventing its own storage.

## Key-value store

`$app->store()` is durable site-wide storage — string keys, JSON values —
for the things an action needs to _keep_: form submissions, counters, site
state. Where sessions are per-visitor and expiring, the store remembers
indefinitely, keyed by what the data is rather than who sent it:

```php
// routes/subscribe/+action.php
$hash = hash('sha256', strtolower(trim($email)));

if (!$app->store()->add("email:$hash", ['email' => $email, 'created' => gmdate('c')])) {
    return ActionResult::failure(['error' => 'Already subscribed.']);
}
```

`add()` is the uniqueness primitive: an atomic insert-if-absent that
returns `false` when the key already exists — no check-then-insert race
between concurrent POSTs, because the key _is_ the primary key. `set()` is
the upsert for genuinely mutable keys, and `get(key, default)` / `has()` /
`remove()` mirror the `Session` surface exactly. Multi-item data follows a
one-key-per-item convention (`email:<hash>`, not one growing array under a
single key — a read-modify-write on a shared array would race and lose
updates); key construction stays in userland — Garner does not hash,
normalize, or namespace on the site's behalf. `items(prefix)` lists a
namespace as an Illuminate Collection keyed by full key (plain string
prefix matching — `:` is a convention, not an API concept; note it loads
the whole namespace, fine at the hundreds-of-items scale this targets),
and `count(prefix)` answers "how many" without loading anything.

Values are JSON: scalars, lists, and maps round-trip; the contract is
"JSON-encodable in, decoded value out," so objects come back as arrays by
contract, and a non-encodable value throws rather than storing garbage.
One caveat inherited from PHP's JSON functions: whole-number floats are
preserved on the write Garner controls (`2.0` stays a float), but a site
needing exact float typing through arbitrary tooling should store the
value as a string. This is deliberately a key-value store, not a database
layer — no queries into values, no TTL, no multiple stores. A site that
outgrows it brings its own PDO connection (SQLite is already a hard
dependency) and its own file under `storage/`.

Data lives in a single SQLite file, `storage/store.sqlite` by default
(`store.path` config), created lazily on first write — never touching the
store never creates the file. Unlike `runtime/index.sqlite` (a disposable,
rebuildable cache), the store is canonical: there is nothing to rebuild it
from, so back up `storage/` and ignore `runtime/`. The file is kept
owner-only (0600, re-asserted when a process first writes; a created
storage directory is 0700 — store values are site data, possibly personal,
the same stance as session files), and Garner refuses to open a
`store.sqlite` that is a symlink — a link there is never
Garner's own doing. The store is
never a black box: values sit as JSON in a plain TEXT column, inspectable
with the `sqlite3` CLI or the console commands:

```sh
php bin/garner store:list [prefix] [--json]   # list items, optionally by prefix
php bin/garner store:get <key>                # print one value as JSON
php bin/garner store:set <key> <json>         # upsert a value ('"text"', '42', '{"a":1}')
php bin/garner store:remove <key>             # delete a key
```

## Configuration

See `config/app.php`. Notable keys: `debug`, `url` (site base URL — see below),
`ids.generator` (`cuid2` default, also `ulid`, `uuid_v4`, `uuid_v7`, or a custom
generator), `index.mode`, `rendering.default_template`, `twig.*`,
`session.*` (`cookie`, `lifetime`, `store`, `path` — see Sessions above),
`store.path` (where the key-value store keeps its SQLite file — see
Key-value store above), and
`csrf.check_origin` (on by default: cross-site form POSTs — mismatched
`Origin` / `Sec-Fetch-Site` — answer 403; JSON APIs and header-less
non-browser clients are unaffected).

Environment variables (`APP_URL`, `APP_DEBUG`, `APP_ENV`) can come from the real
environment or from a `.env` file in the project root, loaded via `symfony/dotenv`
before config is read. The Symfony cascade applies — `.env`, `.env.local`,
`.env.{APP_ENV}`, `.env.{APP_ENV}.local` — and real environment variables always
win over file values. Variables are read from the process environment (`getenv()`)
first, then `$_ENV` and `$_SERVER` (the stock php.ini leaves `$_ENV` empty), so a
deployment can skip `.env` entirely and configure through the server. The file is optional; keep `.env` out of version
control (it may hold secrets) and commit a `.env.example` documenting the keys
instead.

`site.url` is the site's base URL (`scheme://host`, no trailing slash), available
in templates and via `Application::siteUrl()`. It is inferred from each request by
default; set `app.url` (or the `APP_URL` env) to pin a canonical origin — needed
for CLI builds, sitemaps, and stable canonical URLs.

One rule across the API: **`url()` means absolute URL, `path()` means route path.**
`page.url` is the page's full URL (`site.url` plus the route path, e.g.
`https://example.com/about`) — ready for hrefs, sitemaps, `og:url`, and
`rel=canonical` as-is. `page.path` is the bare route path (`/about`): the page's
routing identity, independent of where the site is hosted.

## Development

```sh
composer test       # PHPUnit
composer analyze    # PHPStan (level 7)
composer lint       # Mago
composer format     # Mago
composer check      # platform check + analyze + test
```

## Built with

Twig, league/commonmark, lemmon/validator, illuminate/collections, and Symfony
components (console, yaml, uid, error-handler, var-dumper).

## License

Copyright © Jakub Pelák. All rights reserved. See [LICENSE](LICENSE).
