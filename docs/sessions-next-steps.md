# Sessions and flash state — proposed next steps

> Status: **implemented (2026-07-05).** Built as proposed below, with the
> three open questions resolved during implementation (see "Decided" at the
> end). This is a design doc for review, in the shape of
> `form-actions-next-steps.md` — decisions get recorded here as they're made.

## Context

Garner has shipped a working action layer (`+action.php`, `ActionResult`,
origin-check CSRF, PRG redirects) without any session story, deliberately —
`docs/brainstorming.md` and `docs/form-actions-next-steps.md` both flagged
sessions as an open question rather than build one speculatively.

That deferral is now costing something concrete: the action layer's own
"still open" list names flash messages as blocked on sessions existing, and a
failure re-render only covers the single-request case (same request,
same response). A success redirect (Post/Redirect/Get) has no way to carry a
"saved!" message to the page it lands on except baking it into the URL
(`/?subscribed=1`), which doesn't generalize (arbitrary messages, multiple
concurrent messages, non-boolean state).

Two other things changed since the original deferral:

- `Request` and `RenderedResponse` now have real cookie support
  (`Request::cookie()`, `RenderedResponse::withCookie()`), so the primitive a
  session needs from the HTTP layer already exists.
- The project layout's `storage/` directory (`config/app.php` →
  `paths.storage`), documented in the README as "persistent app state," has
  no occupant yet.

## Scope decision

Sessions-for-login and sessions-for-flash-messages are different problems
wearing the same name. Building a full auth/session-user subsystem
speculatively repeats the mistake the "named actions" section of
`form-actions-next-steps.md` warns against: don't add the general case until
a real site needs it.

**Proposed v1 scope: a generic key/value session primitive, with flash
messages built on top.** Not an auth system, not tied to "users" or "login" —
just durable per-visitor state a controller or action can read and write.
A future login feature would consume this primitive (e.g. storing a user id
in it) rather than inventing its own storage.

## Direction: Garner-owned, not native PHP sessions

Two candidate approaches:

### A. Native PHP sessions (`session_start()` + `$_SESSION`)

The LAMP-native default, and the least code to write. Rejected for v1
because it conflicts with three things this codebase has already paid to
establish:

- **No superglobals in request-dependent code.** `$_SESSION` is the same
  problem `Core\Request` was rewritten to eliminate (see the "Changed"
  section of `CHANGELOG.md` — static superglobal access made
  request-dependent code untestable).
- **One response-emission path.** `session_start()` / session cookie writes
  happen via PHP's own `header()`/`setcookie()` calls, bypassing
  `RenderedResponse::send()` — the CHANGELOG calls the single emission path
  out as a deliberate invariant, not an accident.
- **Immutable, composable response building.** `RenderedResponse`'s
  `with*()` methods return copies precisely so a response can be built up
  fluently and predictably; a side-channel cookie write undermines that.

### B. A Garner-owned `Session` facade (proposed)

Same shape as two things already in the codebase — `Request` (a thin,
instance-based facade over HttpFoundation, obtained via
`Application::request()`) and `IdGeneratorType`/`IdGenerator` (a pluggable
seam: enum case, instance, class-string, or callable, selected through
config). A session store follows the same pluggable-seam pattern.

## Proposed shape

```php
namespace Garner\Core;

interface SessionStore
{
    /** Whether $id names a live session this store issued (fixation seam). */
    public function exists(string $id): bool;

    /** @return array<string, mixed> */
    public function read(string $id): array;

    /** @param array<string, mixed> $data */
    public function write(string $id, array $data, int $lifetime): void;

    public function destroy(string $id): void;

    /** Sweep expired entries. No argument — each entry carries its own expiry. */
    public function gc(): void;
}
```

- `Garner\Core\FileSessionStore` — the default: one file per session
  under `storage/sessions/`, matching the README's "persistent app state"
  description of `storage/`. No new dependency (no Redis requirement),
  consistent with Garner's shared-hosting-friendly, dependency-light stance.
  (Originally sketched as a separate `Garner\Session` namespace; shipped in
  `Core` alongside `Session` and `SessionStore` — three small classes didn't
  earn a namespace of their own.)
- `Garner\Core\Session` — obtained via `Application::session()`, mirroring
  `Application::request()`. Surface: `get(string $key, mixed $default = null): mixed`,
  `set(string $key, mixed $value): void`, `remove(string $key): void`,
  `flash(string $key, mixed $value): void`, `consumeFlash(string $key, mixed $default = null): mixed`,
  `regenerate(): void` (rotate the session id — needed the moment any future
  login feature lands, to prevent session fixation), `destroy(): void`.
- Config, `app.session`: `store` (instance/class-string/callable, file
  default — same shape as `app.ids.generator`), `path` (default
  `storage/sessions`), `cookie` (name, default `garner_session`), `lifetime`
  (seconds — proposed "e.g. 2 weeks", decided 2 hours; see "Decided"). The
  cookie's `secure` flag is inferred from `Request::isHttps()` with no
  config override in v1 — a `secure` key was sketched here but dropped: no
  concrete case for overriding the inference has appeared, and a wrong
  override is strictly worse than the inference.

## Proposed behavior

- **Lazy, opt-in activation is the load-bearing design point.** A session is
  not created — and no `Set-Cookie` is emitted — until code first calls
  `session()->set()` or `session()->flash()`. A plain content page that never
  touches the session stays exactly as stateless and cache-friendly as it is
  today. This matters more for Garner than for a typical app framework: most
  pages on a flat-file CMS are anonymous reads, and a session cookie on every
  response would quietly break reverse-proxy/CDN caching for pages that
  never needed it.
- **Reading** an incoming session cookie is cheap and always allowed (no
  write, no new cookie) — a controller can check `session()->get('foo')`
  without activating anything if the visitor has no session yet (`get()`
  simply returns the default).
- **The session id cookie rides the existing `RenderedResponse` cookie path**
  (`withCookie()`), attached by whatever code turns the eventual `ActionResult`
  / controller return value into a `RenderedResponse` — not a side-channel
  `header()` call. This keeps HTMX responses, redirects, and JSON responses
  all working the same way they do today.
- **An incoming session id is never trusted blindly.** If the cookie names an
  id the store doesn't recognize (expired, evicted, forged), treat it as no
  session rather than adopting the client-supplied id — avoids trivial
  session fixation.
- **Flash values survive exactly one read.** `flash()` writes for "the next
  request only"; `consumeFlash()` reads and clears in the same call. This is
  what finally answers the PRG flash-message gap: an action can
  `session()->flash('notice', 'Subscribed!')` before `ActionResult::redirect()`,
  and the landing page reads it once via `consumeFlash()`.
- **GC** — a `php bin/garner session:gc` command (same shape as `reindex` and
  `cache:clear`) sweeps expired sessions. No implicit per-request GC
  probability roll (avoids the classic "random request pays for cleanup"
  latency spike); production wires it into a deploy/cron hook the same way
  `reindex` is.

## Compatibility

- Purely additive: no existing controller, action, or response contract
  changes shape. `Application::session()` is a new accessor alongside
  `request()`.
- `Application::withSession()` (mirroring `withRequest()`) lets tests inject
  a fake store / fixed session, avoiding any filesystem dependency in unit
  tests — the same testability goal that motivated the `Request` rewrite.

## Explicitly out of scope for v1

- User accounts, login, password handling, "current user" helpers — a future
  feature would be built _on_ this primitive, not bundled with it.
- Token-based CSRF. The origin-check default stays as the baseline
  protection; a session-backed CSRF token becomes possible once this lands,
  but isn't required by it and isn't proposed here.
- Non-file session stores (Redis, database-backed). The `SessionStore`
  interface is designed to make one droppable in later, exactly like a
  custom `IdGenerator`, but v1 ships only the file store.

## Decided

The three open questions above, resolved during implementation:

1. **Cookie lifetime default: 2 hours** (`app.session.lifetime`, seconds),
   short and configurable — v1's scope is flash/lightweight state, not
   long-lived logins. The cookie's own expiry matches the server-side
   lifetime, so there's one source of truth for how long a session lives.
2. **Where the cookie gets attached: `Application::attachSessionCookie()`,
   called by both emitters.** Originally landed in `Router::emit()` alone —
   every response from every producer (origin-check rejection, favicon,
   custom routes, `PublicSite`) passes through it — but review caught the
   gap: `ErrorHandler` has its _own_ `emit()` for error pages, so a request
   that touched the session and then threw would lose its changes and send
   no cookie (a flashed message would resurface, since the aging write
   never happened). The logic now lives on `Application` and both emitters
   call it. It checks `sessionIfStarted()` — null when nothing in the
   request touched the session, so an untouched request pays nothing — and
   otherwise calls `Session::save()` (or expires the cookie if
   `wasDestroyed()`), then attaches `RenderedResponse::withCookie()`. On
   the error path the call is wrapped in a try/catch: it runs inside the
   exception handler, where a second throw (e.g. an unwritable session
   directory — possibly the original error) would replace the error page
   with a blank response. No dispatch path had to change.
3. **Serialization format: PHP's `serialize()`, not JSON** — reversed from
   this doc's original lean. Garner's file-legibility bias is about content
   meant for human editing; nobody hand-edits a session file, so it doesn't
   apply here the way it does to `routes/`. `json_encode()`/`json_decode()`
   carry known PHP round-trip hazards that don't matter for hand-edited
   content but do for values a site stores blindly: a whole-number float
   can lose its type depending on `serialize_precision`, and
   `json_decode(..., true)` always turns an object into a plain array,
   discarding the fact it was ever an object. `serialize()` has neither
   problem. `unserialize()` still reads back with `allowed_classes: false`,
   so a corrupted or tampered file can't be leveraged into a PHP
   object-injection gadget chain — the tradeoff is that an object value
   survives as an object (an Incomplete_Class stub) rather than its
   original class, but at least stays an object rather than silently
   degrading to an array the way JSON guarantees.

Also decided along the way:

- **Session fixation defense**: `SessionStore::exists()` is the seam —
  `Session::fromCookie()` only loads (and only ever writes back under) an
  incoming id the store recognizes as one it issued; an unknown or expired
  id is discarded, never adopted. `FileSessionStore` additionally rejects
  any id that isn't a safe filename (defends the file store specifically
  against path traversal from a tampered cookie value).
- **Session ids don't come from `app.ids.generator`** — review caught that
  `session()` originally reused `Application::idGenerator()`, which a
  project may legitimately configure to something predictable for
  scaffolded content ids. Since a session id is a bearer token (and
  `fromCookie()` accepts any id the store recognizes), a guessable id
  would let one visitor load another's state. Sessions now use a dedicated
  `SecureRandomIdGenerator` (128 bits from `random_bytes()`, hex-encoded),
  not configurable and deliberately independent of the content-id seam.
- **`_flash` is a reserved key** — pending flash values nest under `_flash`
  inside the store payload, so a user value stored at that key would be
  stripped as flash metadata on the next load (or clobbered by pending
  flashes) and could never round-trip. Review flagged the silent loss;
  rather than restructuring the payload (the flat "store entry = session
  data" shape is load-bearing in the store contract and tests),
  `set('_flash', ...)` now throws `InvalidArgumentException`.
- **File store hardening (review)** — two changes to `FileSessionStore`:
  the sessions directory is created 0700 and files chmod'd 0600 before
  becoming visible (originally 0755/umask — on a shared host that exposed
  per-visitor state, and the filenames alone leak the bearer-token ids);
  and writes became atomic — payload to a uniquely named temp file, then
  `rename()` over the real one. The original `file_put_contents(...,
LOCK_EX)` only protected against concurrent _writers_: flock is
  advisory, and the lockless read path could see an empty or half-written
  file, treat a live session as absent, and `gc()` would then sweep it.
  Atomic replacement means a reader always sees a complete file (old or
  new) with no locking required on reads. `gc()` also sweeps orphaned temp
  files (crash residue) after a one-minute grace period.
- **Expected filesystem races don't escalate (review)** — Garner's error
  handler promotes warnings to `ErrorException`, so any store operation
  that can legitimately warn under concurrency had to mute it: a session
  file vanishing (or unreadable) between `is_file()` and
  `file_get_contents()` reads as "no session"; two first-writes racing
  `mkdir()` on the sessions directory both succeed (the loser's "File
  exists" warning is swallowed and the recheck accepts the directory); and
  the various `unlink()` calls tolerate the file already being gone. The
  store's `muted()` helper swaps in a warning-swallowing handler for the
  call's duration (the same pattern its `unserialize()` already used).
  Relatedly, `Request::cookie()` now treats a non-scalar cookie
  (`garner_session[]=x`) as absent instead of letting HttpFoundation throw
  `BadRequestException` — a tampered session cookie follows the documented
  discard path.
- **Flash aging**: a value flashed this request is invisible via
  `consumeFlash()` in the same request (it's pending, not yet persisted);
  it becomes readable on the very next request regardless of whether it's
  consumed, and is never re-persisted from the read side — so an unread
  flash message doesn't linger indefinitely. Loading a session that carries
  flash data marks it dirty, so the store entry is rewritten (without the
  flash) at the end of that request even if nothing else was touched — the
  _aging write_. Without it, a read-only landing page (the typical PRG
  target) would leave the flash in the store and it would resurface on
  every refresh. This is the one exception to "nothing is written until
  `set()`/`flash()`/`destroy()`"; it costs no statelessness, because a
  request loading a flash by definition already carries a session cookie.
- **Store configuration** mirrors `app.ids.generator` exactly: `null` (file
  default), a `SessionStore` instance, a class-string, or a callable
  returning one.
- **The shipped surface grew two peek helpers** beyond the proposal:
  `has(key)` and `hasFlash(key)` (check for a flash without consuming it) —
  small read-only additions, documented in `llms.txt`.

## Near-term next steps

Original build sequence, now complete:

1. ~~Confirm scope (this doc) and the open questions above.~~
2. ~~Add `SessionStore` interface + `FileSessionStore`~~, following the
   `IdGenerator`/`IdGeneratorType` pluggable-seam pattern.
3. ~~Add `Garner\Core\Session` + `Application::session()` / `withSession()`.~~
4. ~~Wire the pending-cookie seam into response dispatch~~ — landed in
   `Application::attachSessionCookie()`, called from both `Router::emit()`
   and `ErrorHandler::emit()` (see "Decided" above), covering controllers,
   actions, endpoints, and error pages uniformly.
5. ~~Add `flash()` / `consumeFlash()`~~.
6. ~~Add `php bin/garner session:gc`.~~
7. ~~Tests~~: `FileSessionStoreTest` (store contract, expiry, path-traversal
   rejection, gc), `SessionTest` (dirty-tracking, fixation resistance, flash
   aging, regenerate, destroy — against an in-memory store double),
   `ApplicationSessionTest` (config resolution: store/path/cookie/lifetime),
   `ApplicationSessionCookieTest` (the actual cookie-attachment and
   persistence wiring — tested on `Application` directly, since both
   emitters that call it end in `exit()`).
8. ~~Document in README + `llms.txt`~~.

Not yet prototyped against a real form (the prototype notify-me form still
answers success via `/?subscribed=1`, not flash) — a good first real
consumer once one is needed.

Still explicitly out of scope, unchanged from the original proposal: user
accounts/login/auth, token-based CSRF, and non-file session stores (the
seam exists; only the file store ships).
