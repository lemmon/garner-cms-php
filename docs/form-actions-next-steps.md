# Form actions and HTTP handling - initial next steps

> Status: **reviewed, paused.** Initial ideas were recorded, then a design
> review pass (2026-07-03) folded its conclusions in: several points moved
> from open to decided, and the contract surface was slimmed. Work is
> intentionally paused before the prototype. When picking this up, start at
> step 1 of the near-term steps and re-test every decision against the first
> real form flow.

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

### Current gaps

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
  (the same class of trap as the pageless-404 finding fixed in the splash).
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

**Leaning for the partial API:** Twig named-block rendering of the _same_ page
template (`renderBlock`) — fragments live inside the page template they belong
to, no new file type. This is the established htmx "template fragments"
pattern. To be confirmed by the prototype.

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

Still open (the prototype decides):

- Whether `ActionResult` survives, or collapses to
  array | redirect | `RenderedResponse`.
- The named-action URL scheme, if and when multiple actions per route arrive
  (`?/name`, `?action=name`, a submit-button field, or route endpoints).
- Flash state — leaning: none until sessions exist; failure-data re-render
  covers the common case, success messages ride the redirect target.
- How `lemmon/validator` integrates with action failure.
- Confirming the `renderBlock` partial approach against a real fragment.

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
4. Add the origin-check CSRF default.
5. Prototype `+action.php` on a real flow: the splash's "notify me on
   release" email-capture form — single field, spam-exposed (exercises the
   origin check and an honest honeypot), failure re-render, 303 success. It
   touches every behavior above with real stakes.
6. Add tests for POST dispatch, missing action 405 (+ `Allow`), validation
   failure re-render, redirect success, HTMX partial response, and
   origin-check rejection.
7. Revisit this document after the prototype and delete anything that proved
   too clever or too vague.
