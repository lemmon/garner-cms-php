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

### Co-located template and controller

Two optional `+` files let a page override its view and behavior:

- **`+template.twig`** — the page's own Twig view. It can `{% extends %}` /
  `{% include %}` anything in `app/templates`. Overrides the `template` field.
- **`+controller.php`** — returns an array (merged into the template context) or a
  `RenderedResponse` (bypasses Twig — e.g. JSON). Overrides the template-based
  `app/controllers/{template}.php`.

```php
<?php // routes/api/+controller.php

use Garner\Render\RenderedResponse;

return static fn($page, $site, $app) => RenderedResponse::json(['ok' => true]);
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

## Configuration

See `config/app.php`. Notable keys: `debug`, `url` (site base URL — see below),
`ids.generator` (`cuid2` default, also `ulid`, `uuid_v4`, `uuid_v7`, or a custom
generator), `index.mode`, `rendering.default_template`, and `twig.*`.

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
