# Form actions and HTTP handling - initial next steps

> Status: **shipped (2026-07-05; cleanup pass 2026-07-06).** The action layer
> shipped and was exercised on a real notify-me email-capture form (steps 5–6
> below). What the prototype decided is folded into "Decided vs still open".
> Partials, flash, and sessions have since shipped too (see
> `docs/sessions-next-steps.md`); the one genuinely open point left is named
> actions, deferred until a real site needs multiple mutation vectors per
> route.

## Context

Garner's current page model is working well for filesystem-routed GET pages:

- route directories define URLs;
- `+template.twig` renders the page;
- `+controller.php` can provide template data or return a `RenderedResponse`;
- controller-only directories can act as lightweight endpoints.

That is enough to make a simple POST work today. A controller can branch on the
request method, read `$_POST` or the raw body, then return HTML, JSON, text, or a
redirect.

For a small contact form, that may be acceptable. For a multi-step registration
flow, it starts to feel awkward because a single controller becomes responsible
for two different concerns:

- loading read-side page state for rendering;
- handling write-side form actions and side effects.

This is the main scaling concern.

## Findings

### What works today

- POST requests are not rejected by the router. Routing is path based.
- Page controllers and endpoint controllers can return full responses.
- HTMX swaps are compatible at the basic HTTP level because they are just HTML
  responses.
- Success redirects can already be represented with
  `RenderedResponse::redirect($target, 303)`.
- Validation failure can already be represented by returning extra template
  context from the controller, such as `errors` and `values`.

### Gaps at the time of writing

_Historical snapshot (2026-07-03) — every gap below has since been closed; see
the status header and `CHANGELOG.md`._

- `Garner\Core\Request` exists but is six static helpers over superglobals
  (scheme/host inference, path, query, raw body, JSON payload). No method,
  headers, form data, uploaded files, cookies, or HTMX helpers — and static
  superglobal access makes request-dependent code untestable. (Its
  `getInput()` / `getPayload()` also predate the no-`get` accessor rule.)
- There is no arbitrary response-header support on `RenderedResponse`, which
  makes HTMX headers such as `HX-Redirect`, `HX-Location`, and `HX-Trigger`
  awkward.
- There is no first-class partial rendering API.
- There is no CSRF/session/flash-message story.
- The controller contract does not distinguish page loading from form
  mutations.
- POST handling is currently an untested assumption, not an exercised design.

## Direction

Use `symfony/http-foundation` as the low-level HTTP substrate, but keep Garner's
own routing, rendering, and controller model.

This should not become Symfony Routing or HttpKernel. Garner's filesystem route
tree is the product. HttpFoundation would only give us reliable request and
response primitives: method, headers, query and request bags, cookies, file
uploads, streamed/raw body access, testable request creation, response headers
and cookies.

**Decided: exactly one request object in userland.** Garner ships a
Garner-styled `Request` facade wrapping HttpFoundation internally,
instance-based. The current static `Core\Request` is replaced, not kept
alongside — two request objects with different capabilities is the failure
mode. HttpFoundation is not exposed directly: its `getMethod()` /
`getContent()` naming collides with the house bare-accessor rule, and the
facade keeps the public surface small. The "testable request creation" benefit
only materializes if Garner's own API stops reading superglobals statically.
`RenderedResponse` stays Garner-owned and gains headers + cookies (backed by
an HttpFoundation response internally).

## Proposed page shape

```text
routes/register/+page.json
routes/register/+template.twig
routes/register/+controller.php   # read-side page context
routes/register/+action.php       # write-side POST action
```

The filename is decided: `+action.php`, by consistency with `+controller.php`
(the sibling is not `+page.controller.php`).

`+controller.php` remains responsible for render context. `+action.php`
handles the route's POST action, receiving the same contract as controllers
with the request prepended (see contracts below).

## Proposed behavior

- `GET /register` runs the page controller and renders the template.
- `POST /register` runs the page's `+action.php` callback.
- `POST /register` without a matching `+action.php` returns
  `405 Method Not Allowed`, with an `Allow` header listing what the route
  accepts.
- `HEAD` routes like `GET`.
- The action layer is POST-only. Other verbs (PUT/DELETE/PATCH, JSON APIs)
  remain the business of route endpoints, which keep full method freedom.
- An action failure re-renders the same page with the failure data available
  to the template as `form`.
- The `form` template variable is **always defined** — `null` on plain GET
  renders — so templates never silently depend on lax `strict_variables`
  (the same class of trap as the pageless-404 finding fixed in an early
  consumer site).
- An action redirect returns a proper redirect response, `303 See Other` by
  default for Post/Redirect/Get. (`RenderedResponse::redirect()` defaults to
  308 method-preserving — right for canonical redirects, wrong inside actions;
  the action path defaults to 303.)
- An action may return a full `RenderedResponse` for JSON, text, custom HTML,
  or HTMX-specific responses.
- Existing controller-returned responses continue to work for endpoints and
  specialized pages.

## CSRF (decided: ships with the MVP)

An action layer without default protection is an insecure-by-default
primitive, so CSRF cannot wait for the session story. The default that fits
Garner's stateless flat-file model is the one SvelteKit itself uses: **origin
checking on POST** — compare `Origin` / `Sec-Fetch-Site` against the site
origin; modern browsers always send them. Stateless, no sessions, a few
lines, with a config off-switch for API-style routes. Token-based CSRF is
deferred until sessions exist; the default protection is not.

## Proposed contracts

Slimmed after review. SvelteKit's event object and `fail()` exist for JS
destructuring and serializing results across a network boundary; server-side
rendered PHP needs less. The MVP surface:

- **Action signature** mirrors the controller contract with the request
  prepended: `(Request, Page, Site, Application)`. No `ActionEvent` wrapper
  with duplicate accessors — one contract shape across the codebase.
- **Failure** → `ActionResult::failure(array $data, int $status = 422)`:
  re-renders the page with the data as `form`.
- **Success** → a 303 redirect (Post/Redirect/Get).
- **Escape hatch** → return a full `RenderedResponse`.

`ActionResult::success()` is deliberately not in the MVP — the
success-without-navigation case is the HTMX-fragment case, served by the
partial API or a `RenderedResponse`. Whether `ActionResult` survives at all,
or collapses to array | redirect | `RenderedResponse`, is for the prototype
to decide. Each constructor must earn its place.

## HTMX implications

HTMX should not require a separate routing model.

The action layer should make these easy:

- detect `HX-Request`;
- return a full page for normal browser POSTs;
- return a fragment for HTMX POSTs;
- emit HTMX response headers;
- redirect either with regular `Location` or an HTMX-specific header when needed.

This probably requires two pieces:

- request helpers such as `isHtmx()`;
- response helpers such as `withHeader()`, `hxRedirect()`, and `partial()`.
  (Of these only `withHeader()` shipped as sketched. No `hxRedirect()` or
  `partial()` methods were needed: the htmx redirect translation lives inside
  `ActionResult::redirect()`, and partial rendering became
  `renderPageFragment()` — see the decision below.)

**Decided (2026-07-05): the partial API is Twig named-block rendering of the
_same_ page template** (`renderPageFragment()` on the renderer) — fragments
live inside the page template they belong to, no new file type. This is the
established htmx "template fragments" pattern. First consumer: failure
re-renders, via `ActionResult::failure(data, status, fragment: 'block')` —
an htmx POST failure answers with just the named block (same rebuilt
context, same status) so the form swaps in place. The motivating gotcha:
htmx ignores `4xx` responses by default, so a full-page 422 re-render was a
_silent no-op_ for an htmx form. The fragment answer keeps the honest 422;
the site opts htmx into swapping it with the `htmx-config` meta tag
(documented in the README). Success-side navigation was already covered by
the `HX-Redirect` translation of `ActionResult::redirect()`. Known
trade-off of `renderBlock`: the block renders alone, so `{% set %}`
statements outside it do not run — fragment blocks must be self-contained
(derived values go in the controller or inside the block); a template that
cannot comply skips the fragment and uses client-side `hx-select` against
the full re-render instead.

## Compatibility

Keep the current controller behavior:

- `+controller.php` may still return an array for render context;
- `+controller.php` may still return a `RenderedResponse`;
- controller-only route endpoints remain valid;
- existing simple POST branching inside a controller should keep working, even if
  it is no longer the recommended pattern for larger forms.

The action layer should be additive.

## Possible future expansion

If one action per route proves too restrictive, add named action files without
changing the default convention:

```text
routes/article/+action.php          # default POST action
routes/article/+action.delete.php   # named delete action
routes/article/+action.publish.php  # named publish action
```

Do not add this until a real application needs multiple mutation vectors on the
same page. A single callback per file keeps the initial model easier to read,
test, and edit.

## Decided vs still open

Decided (2026-07-03 review pass):

- `+action.php` is the filename — consistency with `+controller.php`.
- One request object: a Garner facade over HttpFoundation; the static
  `Core\Request` is replaced, not kept alongside.
- CSRF default: origin checking on POST, shipped with the action layer.
- `form` is always defined in page render context; 405 carries `Allow`;
  `HEAD` routes like `GET`; the action layer is POST-only.
- Index invalidation for form-driven content writes was never open — it is
  already answered by `docs/index-freshness.md`: Garner owns the write, so
  the write path invalidates/rebuilds the index inline.

Decided by the prototype (2026-07-05, a real notify-me form):

- **`ActionResult` survives**, with two constructors that each carry a default
  a bare `RenderedResponse` gets wrong inside actions:
  `failure(array $data, int $status = 422)` (re-render with `form`) and
  `redirect(string $location, int $status = 303)` (Post/Redirect/Get).
  `success()` stayed out. Everything else is the `RenderedResponse` escape
  hatch — the HTMX-fragment case is served by it today.
- **Pre-action compatibility**: for POST without `+action.php` (and any other
  non-GET/HEAD verb on a page), the page's controllers run first — a returned
  `RenderedResponse` still answers the request (existing POST branching keeps
  working), while a context array means the verb is unhandled and yields the
  405 + `Allow`. Endpoints keep full method freedom, untouched.
- **The failure re-render is the GET render plus `form`**: read-side
  controllers are dispatched with the request presented as a true GET
  (`Request::asGet()` via `Application::withRequest()`) — method reads GET
  and the submitted payload is dropped (no form fields, files, body, or
  Content-Type; URL, query, headers, and cookies stay). A method-branching
  controller can neither hijack the re-render with its POST response nor
  starve it of context behind a GET guard, and context built from `form()` /
  `json()` / `body()` / `file()` cannot react to the already-handled
  submission. `form` is reserved for the action layer — always defined,
  `null` outside a failure, and not overridable from controller data.
- **`lemmon/validator` integrates without coupling**: the prototype action uses
  `tryValidate()` and feeds the first error message into
  `ActionResult::failure()` by hand. No framework-level bridge needed yet.
- **The origin-check softenings from step 4 held**: the real same-origin form
  passed cleanly, cross-origin POST answered 403, and the honest honeypot
  (visibly-labeled, `display: none`, action answers it with the normal
  success redirect and stores nothing) covers non-browser spam that the
  origin check deliberately lets through.

Still open:

- The named-action URL scheme, if and when multiple actions per route arrive
  (`?/name`, `?action=name`, a submit-button field, or route endpoints).
- ~~Flash state — leaning: none until sessions exist; failure-data re-render
  covers the common case, success messages ride the redirect target
  (the prototype uses `/?subscribed=1`).~~ **Resolved (2026-07-05):** sessions
  shipped with `flash()` / `consumeFlash()` (see
  `docs/sessions-next-steps.md`), so a success message can ride a flash
  across the PRG redirect instead of the URL.
- ~~Confirming the `renderBlock` partial approach against a real fragment.~~
  **Done (2026-07-05):** built as `renderPageFragment()` +
  `ActionResult::failure(..., fragment:)`, driven by the htmx failure
  gotcha (4xx responses don't swap by default, so a full-page 422 re-render
  was a silent no-op for htmx forms). See "HTMX implications" above.
  Fragments beyond failure re-renders (e.g. `hx-get` partials) still go
  through the `RenderedResponse` escape hatch.

## Near-term next steps

1. ~~Replace the static `Core\Request` with the HttpFoundation-backed facade;
   keep the public API small and bare-accessor styled.~~ **Done (2026-07-03):**
   instance-based facade held by `Application::request()` (injectable for
   tests); `getInput()`/`getPayload()` dropped, body/JSON/form accessors
   arrive with step 3.
2. ~~Extend `RenderedResponse` with arbitrary headers and cookies.~~
   **Done (2026-07-04):** immutable `withHeader()` / `withCookie()` backed by an
   HttpFoundation response internally; one emission path (`send()`), static
   `Core\Response` removed.
3. ~~Add request helpers for method, headers, form data, files, JSON, and
   HTMX.~~ **Done (2026-07-04):** `header()`, `cookie()`, `body()`, `form()`,
   `json()`, `file()` (Garner `UploadedFile` facade), `isHtmx()`;
   `Request::create()` builds test requests with parameters, cookies, files,
   and a raw body.
4. ~~Add the origin-check CSRF default.~~ **Done (2026-07-04):**
   `Core\OriginCheck`, enforced in the router for pages, endpoints, and custom
   routes alike; `app.csrf.check_origin` config (default true). Deliberate
   deviations from SvelteKit's strict origin equality, to re-test against the
   real form flow in step 5: (a) a form POST with _neither_ `Origin` nor
   `Sec-Fetch-Site` passes (curl, webhook deliveries — no browser, no ambient
   credentials), so rejection requires positive cross-site evidence;
   (b) `Sec-Fetch-Site` outranks the `Origin` comparison, and the `Origin`
   fallback accepts an `https` origin against an `http` base on the same
   host — both so TLS-terminating proxies that hide the protocol don't 403
   legitimate same-origin forms.
5. ~~Prototype `+action.php` on a real flow: a "notify me" email-capture
   form — single field, spam-exposed (exercises the origin check and an
   honest honeypot), failure re-render, 303 success. It
   touches every behavior above with real stakes.~~ **Done (2026-07-05):**
   action layer shipped (`+action.php` discovery, `PageActions`,
   `ActionResult`, method-aware page dispatch with 405 + `Allow`, `form`
   always defined, HEAD routes like GET) and the form is live on it —
   honeypot, `lemmon/validator` failure re-render (422), `/?subscribed=1`
   Post/Redirect/Get, verified in a real browser. Findings recorded under
   "Decided vs still open".
6. ~~Add tests for POST dispatch, missing action 405 (+ `Allow`), validation
   failure re-render, redirect success, HTMX partial response, and
   origin-check rejection.~~ **Done (2026-07-05):** `tests/ActionTest.php`
   covers POST dispatch, 405 + `Allow` (with and without an action), failure
   re-render + `form`, 303 redirect, HTMX via the `RenderedResponse` escape
   hatch, HEAD-like-GET, endpoint method freedom, controller POST-branching
   compatibility, and the invalid-return guard; origin-check rejection was
   already covered by `tests/OriginCheckTest.php` in step 4.
7. ~~Revisit this document after the prototype and delete anything that proved
   too clever or too vague.~~ **Done (2026-07-06):** stale present-tense gaps
   marked historical, the never-shipped `hxRedirect()`/`partial()` sketches
   annotated, and the flash item closed out against the shipped sessions.
