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

## Rendering

Twig templates live in `app/templates/`, resolved by the page's `template` field
(falling back to `default`). Markdown is rendered through `league/commonmark`,
exposed as a `markdown` Twig filter:

```twig
<h1>{{ page.title }}</h1>
{{ content.main|markdown }}
```

## Configuration

See `config/app.php`. Notable keys: `debug`, `ids.generator` (`cuid2` default,
also `ulid`, `uuid_v4`, `uuid_v7`, or a custom generator), `index.mode`,
`rendering.default_template`, and `twig.*`.

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
