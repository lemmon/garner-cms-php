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
app/       templates, controllers, routes.php, favicon; site classes in app/src/
config/    configuration (config/app.php)
public/    web root — point the document root here (public/index.php)
routes/    the page tree (one directory per route)
runtime/   derived index + caches (disposable, rebuildable)
storage/   persistent app state
```

## Using Garner as a package

A site can require Garner as a Composer dependency and keep only its own
content and configuration (`routes/`, `app/`, `config/`, `public/`):

```sh
composer require garner/cms
```

The web entry point is a two-liner — `public/index.php`:

```php
<?php

declare(strict_types=1);

define('GARNER_PROJECT_ROOT', dirname(__DIR__));

require dirname(__DIR__) . '/vendor/garner/cms/boot/web.php';
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
php -S localhost:8000 -t public vendor/garner/cms/boot/server.php
```

The command line tool is Composer's `vendor/bin/garner`. Run it from the
site root: it treats the working directory as the project, unless the
`GARNER_PROJECT_ROOT` environment variable names another one.

```sh
vendor/bin/garner list
```

Commands in this README use that form. In a checkout of Garner itself, the
script is `bin/garner`.

### The site's own PHP classes

Garner looks for a fixed set of lowercase entries directly under `app/`:
`templates/`, `controllers/`, `routes.php`, `twig.php` and `favicon.ico`.
Put the site's own classes (an API client, a webhook handler, Twig helpers)
in `app/src/` and autoload the `App\` namespace from there, in the site's
`composer.json`:

```json
"autoload": { "psr-4": { "App\\": "app/src/" } }
```

Run `composer dump-autoload` after changing it. This keeps everything Garner
reads apart from the site's code, so a future convention directory under
`app/` can't collide with a class directory. A root-level `src/` stays free
for front-end sources (`src/css/`, `src/js/`).

## How a page works

A page is a directory under `routes/`. Its route is its path: `routes/+page.json`
→ `/`, `routes/blog/post/+page.json` → `/blog/post`. A directory without an entry
file is a non-routable container; its children still route.

Route paths are canonical without a trailing slash (the root `/` being the only
exception). A request that differs from a routable path only by slashes (`/about/`,
`/about////`) gets a permanent redirect (308, query string preserved) to the
canonical form, so the same content is never served at more than one URL. Paths
whose canonical form doesn't route just 404.

A directory with a `+controller.php` and/or `+action.php` but no entry file is a
**route endpoint**: it routes and dispatches, but carries no metadata and is
excluded from the page tree — it never appears in `site.index`, `children`, or
`findById`. Use it for `sitemap.txt`, feeds, and JSON APIs that should not be
treated as content pages. Method dispatch works exactly like a page (see
[Form actions](#form-actions)): GET/HEAD is the controller's, POST is exclusively
`+action.php`'s when one exists, and everything else is `405`. See
[Action-only endpoints](#action-only-endpoints) below for the POST-only shape
(no `+controller.php` at all).

### The `+page.json` contract

`+page.json` is the only file Garner constrains, and it has no required fields — a
directory with a `+page.json` (even `{}`) is a page. Any keys are kept as freeform
metadata.

| Field           | Default                                 | Notes                                      |
| --------------- | --------------------------------------- | ------------------------------------------ |
| `id`            | the directory name (`home` at the root) | Any unique string; explicit value wins.    |
| `template`      | `default`                               | Twig template / controller name.           |
| `draft`         | `false`                                 | Hides the page and its route descendants.  |
| `draft_preview` | none                                    | Unlisted preview link secret; see below.   |
| `sort`          | `0`                                     | Integer; lower comes first in listings.    |
| `created`       | none                                    | Non-empty string (timestamp) when present. |

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

Garner has exactly one publication flag in core: `draft`. A draft and everything
beneath it in the route tree 404 publicly and are excluded from listings.

| Effective state          | Resolves at URL | In listings |
| ------------------------ | --------------- | ----------- |
| published                | yes             | yes         |
| draft or beneath a draft | no (404)        | no          |

`page.isDraft` reports the page's own flag; `page.isHidden` reports the
effective state after inheriting visibility from its ancestors.

Toggle the flag from the shell rather than hand-editing `+page.json`:

```shell
garner page:draft blog/hello     # sets "draft": true
garner page:publish blog/hello   # removes the "draft" key entirely
```

Both are no-ops (not errors) when the page is already in the target state,
and only edit a `+page.json` entry — a `+page.yaml`/`+page.yml` page fails
naming the command instead.

### Draft preview links

Set `draft_preview` on a draft page to hand it to a client for review before it
publishes:

```json
{ "draft": true, "draft_preview": "letmein" }
```

The page then answers at `/path?preview=letmein` — still a 404 without the
matching value, still absent from listings, and served with
`X-Robots-Tag: noindex`. It's a soft, unlisted-link gate for showing someone an
unpublished page, not a mechanism for protecting sensitive data: the value is a
plain string, reuse across pages is fine, and each page's own value only
unlocks that page.

Manage it with the CLI rather than hand-editing when convenient:

```shell
garner page:preview blog/hello --generate   # random token, shown once
garner page:preview blog/hello --set "letmein"
garner page:preview blog/hello              # show the current state
garner page:preview blog/hello --clear      # turn the link off
```

For a quick local look rather than a link handed to someone else, `--open`
issues a one-time token that never touches `+page.json` at all — it lives only
in the disposable application cache, so it can't be redeemed anywhere but the
machine that generated it, and it stops working the moment it's used:

```shell
garner page:preview blog/hello --open --base-url http://localhost:8040
```

`--base-url` defaults to `app.url`/`APP_URL` when set. Unused, the token is
discarded after `app.preview.open_ttl` seconds (default `1800`, i.e. 30
minutes) — pure housekeeping, not a security boundary, since it's already
single-use and redeemable only on the machine that generated it.

**Known limitation:** the token must be on the request's own query string —
it does not survive a redirect that doesn't explicitly carry it forward. A
form submitted on a previewed draft page (`+action.php`'s Post/Redirect/Get
success redirect) or a controller-returned redirect lands on a follow-up
request with no `?preview=`, so it 404s even though the submission itself
succeeded. Preview links are meant for reviewing content, not exercising
interactive flows on an unpublished page; re-append `?preview=` by hand (or
mint a fresh `--open` link, since one-time tokens are spent after the first
view regardless) if you need to keep going.

Finer visibility — "in the footer but not the header", "featured", "archived" — is
a listing decision, not a global page property, so it lives in your own freeform
fields and is filtered with the collection API (see [Traversal](#traversal)):

```twig
{# hide pages that opt out with a "nav": false field #}
{% for child in site.children.reject(child => child.get('nav') is same as false) %}
```

## Ordering

Siblings order by `sort`, then route path. `sort` defaults to `0`, so negatives
pin to the top, positives sink below the defaults, and unset pages sort by path.

Whole-tree listings (`site.index`, `page.index`, `page:list`) are in tree
order: depth-first, each page followed by its own descendants, with `sort`
ordering siblings at every level. A child never comes before its parent,
whatever their `sort` values, so the listing reads like a sitemap. Pages
beneath a grouping directory (one with no entry file) count as children of
the nearest page above it.

## Traversal

Available in templates and via the `Garner\Content\Pages` repository:

- `site.home` — the `/` page, or `null` when `/` is a route endpoint or has
  no entry
- `site.children` — home plus the top-level pages
- `site.index` — home plus every page under it

Without a home page, `site.children` and `site.index` still list the pages
beneath `/` — home is just absent — so a root that only redirects keeps a
working nav and sitemap.

- `page.children` — direct children
- `page.index` — all descendants
- `page.parent` — the nearest ancestor page, or `null` for home
- `page.ancestors` — the full ancestor chain, root first (home ... nearest
  parent) — the order a breadcrumb reads left to right

Listings exclude hidden pages and follow the ordering above. Each returns a
`Garner\Content\PageCollection` (a [Laravel collection](https://laravel.com/docs/collections)
of `Page`), so the full query API is available — `filter`, `reject`, `where`,
`sortBy`, `first`, `take`, plus `published()` and `drafts()`:

```twig
{% for post in page.children.sortBy('created').reverse.take(5) %}
```

String keys in those helpers (`sortBy('created')`, `where('template', 'post')`,
`pluck('title')`, `groupBy(...)`, dot paths like `content.main`) read a page
the way Twig's `page.x` does: an accessor first (`x()`, `getX()`, `isX()`,
`hasX()`, so `created`, `title`, `draft`, `hidden` …), then freeform
`+page.json` metadata. The same lookup lets templates read freeform metadata
as `page.nav`. A key that names a page method (`url`, `children`, `file` …)
is never read from metadata, so use `page.get('url')` for the raw value.
`where('nav', false)` compares loosely, as in Laravel, so it also matches
pages with no `nav`; use `whereStrict` to tell `false` from absent.

```twig
{% for ancestor in page.ancestors %}{{ ancestor.title }} / {% endfor %}{{ page.title }}
```

To include explicit drafts and descendants hidden by them in a listing, pass
`drafts: true`: `page.children(drafts=true)`. `parent()`/`ancestors()` are not
visibility-filtered: grouping directories are skipped, but real ancestor pages
are returned even when they are drafts because the relationship is structural.

The same tree is inspectable from the shell without SQL or template code:

```sh
vendor/bin/garner page:list [path] [--drafts] [--json]   # route, id, title, draft/hidden state
vendor/bin/garner page:show <route-or-id> [--route] [--id] [--json]   # one page's metadata, content, files, template
```

`page:show` accepts either a route path or a stable id; a route lookup
includes drafts, but the id-lookup fallback only resolves visible pages
(`findById()`'s existing contract), so a hidden page must be addressed by
route. If the argument matches both a route and a _different_ page's id —
a nested page's id happening to equal another page's route text — it
fails rather than guessing; pass `--route` or `--id` to say which one you
mean.

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
vendor/bin/garner reindex
```

Compiled Twig templates are cached the same way (`runtime/cache/twig`, never
recompiled in production), so a deploy must refresh both or keep serving stale
pages:

```sh
vendor/bin/garner cache:clear --templates && vendor/bin/garner reindex
```

`cache:clear` with no option also empties the application cache (see
Application cache below); `--app` empties only that. A deploy needs it only
when it changes what cached values mean, such as their shape or the class of
a cached object. Otherwise, keeping it spares the first visitors after a
deploy from recomputing expensive values like remote API responses.

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

The renderer enables CommonMark, GitHub-flavored Markdown (tables, task
lists, strikethrough, autolinks) and attributes (`{#id .class}`). Add more
League CommonMark extensions in `app.markdown.extensions`, after those
defaults, in order. Each entry is an extension class name (constructed
without arguments), an extension instance (for one that takes constructor
arguments), or a callable that receives the CommonMark `Environment` for
anything an extension object doesn't cover, such as a custom renderer. A
callable runs in its place among the extensions as League sets them up, so
what it adds is ordered like an extension's; it may add to the `Environment`
but not read from it, and may return an extension to add. Entries naming a
default are skipped, so a setup copied from League's documentation works as
is. An extension's own settings sit next to it in `app.markdown`, under the
key the extension documents. In `config/app.php`:

```php
use App\Markdown\HighlightedCode; // the site's own renderer, in app/src/
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\Footnote\FootnoteExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;

'markdown' => [
    'html_input' => 'strip',
    'extensions' => [
        FootnoteExtension::class,
        new HeadingPermalinkExtension(),
        static function (Environment $environment): void {
            $environment->addRenderer(FencedCode::class, new HighlightedCode(), 10);
        },
    ],
    'footnote' => ['backref_symbol' => '↑'],
],
```

An entry that is none of the three, or a class name that needs constructor
arguments, fails at boot with an error naming its position. Extensions are
built on the first `markdown` render of a request, so pages without Markdown
never construct them. Every key in `app.markdown` is checked then, so an
invalid or misspelled setting fails on that first render (naming
`app.markdown`) rather than being ignored, as does the same extension listed
twice or two extensions claiming the same syntax. Markdown renders on each
request, not into the compiled template cache, so changing extensions needs
no `cache:clear`.

Two filters cover the usual teaser needs (a listing card, a search result, a
meta description):

- `first_paragraph` renders Markdown like `markdown` but returns only the
  first top-level paragraph that has text, passing over headings, code
  blocks, lists, quotes and image-only paragraphs. Use it on the Markdown
  source in place of `markdown`, not before or after it. Reference links
  resolve against the whole document; footnote markers keep their number,
  but the footnotes themselves aren't included.
- `excerpt(length = 160, ellipsis = '…')` returns plain text of at most
  `length` characters (ellipsis included), cut after the last whole word
  that fits. HTML that Twig knows is safe (the output of `markdown`,
  `first_paragraph`, `raw` or any other safe filter) is read as HTML: tags
  stripped, entities decoded, whitespace collapsed. That is exact for
  Markdown output; HTML you write yourself is stripped as written, so
  `<p>a</p><p>b</p>` reads as `ab` and the contents of a `<style>` remain.
  Any other string is taken literally, so `page.summary|excerpt` keeps a `<`
  or `&` it contains. Twig escapes the result as usual; don't mark it `raw`.

```twig
{% for child in page.children %}
  <a href="{{ child.url }}">{{ child.title }}</a>
  {{ child.content.main|first_paragraph }}
{% endfor %}

<meta name="description" content="{{ content.main|first_paragraph|excerpt(160) }}">
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

When a page routes but the thing it names doesn't exist — an unknown filter
value in the query, a record that's gone — throw `Garner\Core\NotFoundException`
from the controller. Garner answers with the site's `404` template and a
`404` status, exactly as for an unrouted path. The same works from an
`+action.php` and from a custom route handler in `app/routes.php`.

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
- **Validator failure** — `ActionResult::invalid($errors, values: $values,
status: 422, fragment: null)` is the corresponding shortcut for a
  `lemmon/validator` `tryValidate()` result. It exposes the structured errors
  unchanged as `form.errors` and the supplied value as `form.values`.
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

Page dispatch is method-aware and deliberately narrow — Garner models
old-school form/full-navigation behavior, not a REST/JSON-API framework, so
exactly two verbs are ever handled, on every _filesystem_ route shape alike
(a page, a `+controller.php` endpoint, an action-only endpoint), with no
exceptions: `GET`/`HEAD` always belongs to the controller and/or the page
template, `POST` always belongs exclusively to `+action.php` when one
exists, and nothing else is ever dispatched. A controller is `GET`/`HEAD`
only, full stop — it is never invoked for `POST` or any other verb, whether
or not an action exists; a `POST` with no matching `+action.php` is a `405`,
not a fallback to the controller. A verb neither side claims is a
`405 Method Not Allowed` with an `Allow` header naming what the route does
answer. Cross-site form POSTs are already rejected by the origin check
before an action runs.

This guarantee is specific to filesystem routing. A custom route registered
in `app/routes.php` is dispatched ahead of it for any HTTP method — its
handler receives no method information and can return a response for any
verb, including `PUT`/`DELETE`/etc. — so it gets no automatic `405` and is
the site builder's own responsibility to restrict, if it should be.

### Action-only endpoints

A directory with `+action.php` and neither an entry file nor `+controller.php`
is routable too — the POST-axis counterpart to a `+controller.php`-only route
endpoint:

```text
routes/subscribe/+action.php   # no +page.json, no +controller.php
```

- **POST** dispatches `+action.php`, same as a page action. A `failure()`/
  `invalid()` result re-renders a co-located `+template.twig` with `form`
  populated, if one is present — same failure-re-render behavior as a page
  action. With no such template, there is no page to re-render into, so it
  must return a `RenderedResponse` or `ActionResult::redirect()` instead, and
  returning either of the other two throws a `RuntimeException` at dispatch
  time instead of silently 404ing or crashing the renderer.
- **GET / HEAD** (and any other verb) answer `405 Method Not Allowed` with
  `Allow: POST` — the route exists, it just doesn't answer that verb (there is
  no controller here to answer GET with). A co-located `+template.twig`
  doesn't change this: it exists only to give POST's own failure re-render
  something to render into, not to make the route GET-navigable — add a
  `+controller.php` for that.
- Like a controller-only endpoint, it is excluded from the page tree
  (`site.index`, `children`, `findById`) and carries no metadata.
- Still exactly one `+action.php` per directory, and still POST only — the
  same constraints as the page action layer. Garner has no verb model beyond
  GET/HEAD/POST; there is no other-verbs escape hatch.

A directory can also combine `+controller.php` and `+action.php` with no
entry file: GET/HEAD dispatches the controller (same contract as a
controller-only endpoint: an array result renders into a co-located
`+template.twig` if one is present, exactly like a page's controller; with no
such template, it must return a `RenderedResponse` instead), POST dispatches
the action exclusively (same failure-re-render behavior as above), and every
other verb is `405 Method Not Allowed` with `Allow: GET, HEAD, POST`. This
mirrors
exactly how a page-tree route splits GET (controller/template) from POST
(action) — a controller is a GET pre-processor everywhere in Garner, never a
router for arbitrary verbs, whether it's co-located with a page or standing
alone as an endpoint.

### Custom routes

`app/routes.php` registers URLs outside the page tree. It returns an array of
exact paths to handlers; each handler returns a `RenderedResponse`:

```php
<?php

use Garner\Render\RenderedResponse;

return [
    '/feed.json' => static fn($app, $site) => RenderedResponse::json([
        'title' => $site->title(),
        'items' => $site->index()->pluck('url')->all(),
    ]),
    '/webhooks/deploy' => static fn($app) => RenderedResponse::text("ok\n"),
];
```

A handler receives `$app` and, if it declares a second parameter, the
`Site` (the same object templates see as `site`). Leave that parameter
untyped or type it `Site`; any type that can't hold a Site is reported as an
error. The Site is loaded only for handlers that ask for it, so a webhook or
JSON route that takes only `$app` skips that work. Custom routes are matched before the page tree,
for every HTTP method (see Form actions above).

Paths canonicalize the way pages do: a request for `/feed.json/` redirects
with a `308` to the registered `/feed.json`, keeping the query string. A
path registered with its own trailing slash is still matched exactly.
Throwing `Garner\Core\NotFoundException` from a handler renders the site's
`404`.

### Twig extensions

`app/twig.php` extends the Twig environment: it returns a callable
`(Environment $twig, Application $app): void` that registers functions, filters,
or globals. A filter registered here with the name of a built-in one
(`markdown`, `first_paragraph`, `excerpt`) replaces it. Use it for render-time computation that belongs in templates — e.g.
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
`vendor/bin/garner session:gc`
(wire it into a deploy hook or cron, the same way `reindex` is). This is a
generic primitive, not a "logged-in user" concept — a future auth feature
would store a user id in it rather than inventing its own storage.

## Application cache

`$app->cache()` stores disposable computed values — remote API responses,
expensive transformations, or other data that can be recomputed when absent.
It is site-wide like `store()`, but its contract is the opposite: cache data
lives under `runtime/`, may expire, may be cleared at any time (a deploy
can drop it with `cache:clear`), and must never be treated as canonical:

```php
$weather = $app->cache()->remember(
    'weather:bardejov',
    callback: fn() => fetchWeather(),
    ttl: 3600,
);
```

The surface is intentionally small: `get(key, default)`, `set(key, value,
ttl = null)`, `has(key)`, `remove(key)`, `remember(key, callback, ttl = null)`,
and `clear()`. A `null` TTL keeps the value until explicit clearing; a TTL at
or below zero removes it. `remember()` may run the callback in two concurrent
requests that miss at the same time — v1 provides safe complete writes, not a
single-flight lock.

Values use PHP serialization rather than JSON. The cache is opaque,
process-managed runtime state, so round-trip fidelity wins over inspectability:
empty arrays and empty objects stay distinct, floats keep their type, and
serializable application objects retain their class. Classes are allowed when
decoding because the cache file is trusted Garner-written state, kept
owner-only and never populated from an encoded request or content payload.
Closures, resources visible in arrays or ordinary object properties, recursive
arrays, values too deep or complex to inspect safely (nested more than 64
levels deep, or spanning more than 100,000 arrays and objects — generous
enough for a multi-megabyte decoded API response), and serialization
failures are rejected. Objects with opaque internal or custom serialization
state—including internal containers such as `ArrayObject`—are responsible for
producing resource-free state themselves: Garner does not invoke custom
serialization once for validation and again for the actual write. Corrupt or
class-incompatible entries behave as misses and are removed when `get()` or
`remember()` reads them; `has()` checks a row's presence and expiry without
decoding the payload.

The SQLite backing file is `runtime/cache/data.sqlite` by default
(`cache.path` config) and is created lazily on the first `set()`. Clear it
together with compiled Twig templates using `vendor/bin/garner cache:clear`,
or on its own with `vendor/bin/garner cache:clear --app`.

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
vendor/bin/garner store:list [prefix] [--json]   # list items, optionally by prefix
vendor/bin/garner store:get <key>                # print one value as JSON
vendor/bin/garner store:set <key> <json>         # upsert a value ('"text"', '42', '{"a":1}')
vendor/bin/garner store:add <key> <json>         # insert only if absent; fails (nonzero exit) if the key exists
vendor/bin/garner store:count [prefix]           # count items, optionally by prefix
vendor/bin/garner store:remove <key>             # delete a key
```

## Configuration

See `config/app.php`. Notable keys: `debug`, `url` (site base URL — see below),
`ids.generator` (`cuid2` default, also `ulid`, `uuid_v4`, `uuid_v7`, or a custom
generator), `index.mode`, `rendering.default_template`, `twig.*`,
`session.*` (`cookie`, `lifetime`, `store`, `path` — see Sessions above),
`cache.path` (where the disposable application cache keeps its SQLite file),
`store.path` (where the key-value store keeps its SQLite file — see
Key-value store above), and
`csrf.check_origin` (on by default: cross-site form POSTs — mismatched
`Origin` / `Sec-Fetch-Site` — answer 403; JSON APIs and header-less
non-browser clients are unaffected).

`vendor/bin/garner id:generate [--count=N] [--json]` prints one or more fresh ids
from the project's configured `ids.generator` — the same primitive `page:create`
calls internally, useful from the shell or a script that needs an id (e.g.
seeding a `store:set` key) without hand-rolling one in a format the project
isn't actually configured for. `--count` accepts 1–10000.

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

MIT. See [LICENSE](LICENSE).
