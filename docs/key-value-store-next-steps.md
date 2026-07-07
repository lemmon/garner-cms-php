# Key-value storage — proposed next steps

> Status: **implemented (2026-07-05).** Built as proposed below, with the
> four open questions resolved during implementation (see "Decided" at the
> end). A design doc for review, in the shape of
> `form-actions-next-steps.md` and `sessions-next-steps.md` — decisions get
> recorded here as they're made, not assumed up front.

## Context

Garner has no storage API for site data. There is exactly one database in the
project — `runtime/index.sqlite` — and it is internal and disposable: a
rebuildable cache of the route tree, not a place for anything canonical. The
`storage/` directory is documented in the README as "persistent app state"
(`config/app.php` → `paths.storage`) but has no occupant and no code behind it.

The action layer changed the stakes. A `+action.php` that accepts a form
submission usually needs to _keep_ something — a notify-me form
collects email addresses, and today the only answer is bring-your-own PDO
inside the action. That works (`ext-pdo_sqlite` is already a hard
requirement), but it is exactly the kind of boilerplate a framework should
absorb once a real site has proven the need. The action layer's prototype
form is that proof.

This is deliberately **not** the session store. Sessions (shipped — see
`sessions-next-steps.md`) are per-visitor, expiring state with their own
lifecycle (cookies, GC, fixation concerns). This doc is about durable site
data: things a site wants to remember indefinitely, keyed by what they are
rather than who sent them.

## Scope decision

**Proposed v1 scope: a key-value store — string keys, JSON values — on its own
SQLite file under `storage/`.** Not a database layer. The boundary is worth
stating up front because it is the likeliest place for scope creep: the moment
this grows `where()` clauses, indexes on JSON fields, or joins, it is a query
builder and deserves a different design conversation. A site that outgrows
key-value lookup keeps the escape hatch it has today — its own PDO connection,
its own schema, its own file in `storage/`.

## Direction

### Why SQLite and not flat files

Per-key JSON files in `storage/` would be more agent-legible — Garner's usual
bias — but the write path decides it. Concurrent form POSTs need atomic
insert-if-absent and safe concurrent writes, which means file locking Garner
would have to implement and get right. SQLite provides that for free, is
already a hard dependency, and the `sqlite3` CLI is ubiquitous enough that the
file is never a black box. This is the same LAMP-native, shared-hosting-friendly
reasoning as the route index; the legibility cost is mitigated below.

The store lives in its own file (e.g. `storage/store.sqlite`), **not** in
`runtime/index.sqlite`. The two have opposite contracts: the index is derived
and disposable (`runtime/` can be deleted at will); the store is canonical and
persistent — there is nothing to rebuild it from. Backup guidance follows the
directory: back up `storage/`, ignore `runtime/`.

### One key per item, not one array under one key

The tempting shape for "a list of subscribers" is a single key holding a
growing JSON array:

```php
// Rejected.
$list = $store->get('subscribers');
$list[] = $email;
$store->set('subscribers', $list);
```

Rejected on multiple levels: the read-modify-write races against concurrent
POSTs (lost updates — precisely the traffic a spam-exposed public form
invites), the value grows without bound, and uniqueness checks mean scanning
the array in PHP.

Instead, **each item is its own key, namespaced by convention**:

```text
email:<hash-of-address>   →  { "email": "…", "created": "…" }
```

This makes uniqueness _structural_: the key is the primary key, so
"already subscribed?" is not a check-then-insert dance but a single atomic
insert-if-absent — the database answers it. It also keeps values small and
independent, and gives multi-item reads a natural shape (everything under a
prefix).

Key construction stays in userland. Garner does not hash, normalize, or
namespace on the site's behalf — the site decides that `email:` keys use a
lowercased, SHA-256-hashed address. The store's contract is only: keys are
unique strings.

### Values are JSON, returned decoded — no automatic Collections

Decided in review: a value can be a scalar, a map, or a list, and wrapping
`"hello"` or `true` in an illuminate Collection is over-clever. It also
creates a round-tripping question (does `set()` accept a Collection back? does
it re-serialize identically?). The contract stays "JSON-encodable in, decoded
value out."

Where Collections _do_ earn their place is the multi-item read: listing a
namespace returns a Collection keyed by full key, ready for `map` / `filter` /
`count`. That is the explicit, single surface where a Collection is the
natural return type — not per-value magic.

**Why JSON here when sessions chose `serialize()`** (decided 2026-07-05
review): sessions rejected JSON because of round-trip hazards for values
stored blindly — an object silently decoding as a plain array, whole-number
floats losing their type. Those arguments don't transfer to the store,
for two reasons. First, the store's contract is JSON-shaped _at the
boundary_: "JSON-encodable in, decoded value out" means objects don't
round-trip **by contract**, so what was a silent hazard for sessions is a
documented rule here — a site that hands the store an object has left the
contract, not hit an edge case. Second, inspectability is load-bearing for
the store in a way it isn't for sessions: the whole "Agent legibility"
section below (the `store:*` commands, `sqlite3` on a TEXT column) works
_because_ values are JSON; `serialize()` blobs would defeat the mitigation
this doc promises. One residual caveat was predicted here — whole-number
floats coming back as ints (`2.0` → `2`, depending on
`serialize_precision`) — but the implementation preempted it: values are
encoded with `JSON_PRESERVE_ZERO_FRACTION`, so `2.0` is stored as `2.0`
and decodes back as a float. The predicted caveat never shipped.

## Proposed shape

```php
namespace Garner\Store;

final class Store
{
    public function set(string $key, mixed $value): void;            // upsert
    public function add(string $key, mixed $value): bool;            // insert-if-absent, atomic; false if key exists
    public function get(string $key, mixed $default = null): mixed;
    public function has(string $key): bool;
    public function remove(string $key): void;

    /** @return \Illuminate\Support\Collection<string, mixed> keyed by full key */
    public function items(string $prefix = ''): Collection;

    public function count(string $prefix = ''): int;
}
```

- **`add()` is the uniqueness primitive** and the notify-me form's whole write
  path: `$store->add("email:$hash", ['email' => $email, 'created' => $now])` —
  `false` means already subscribed, no race window, no separate `has()` check.
- **`set()` is the upsert** for keys that are genuinely mutable
  (counters, site state, settings).
- **Naming decided (2026-07-05 review):** `get()` / `set()` / `has()` /
  `remove()`, matching the shipped `Session` surface exactly — the two generic
  containers should read identically. This does not conflict with the house
  no-`get`-prefix rule: that rule bans get-prefixed accessors _where a noun
  exists_ (`header()`, not `getHeader()`); a generic container has no noun for
  "the thing under this key," and bare `get($key)` is the established
  convention for that shape (`Session` set the precedent).
- Obtained via `Application::store()`, mirroring `request()` and `session()`.
  `Application::withStore()` follows the _callback-scoped_ shape the shipped
  `withSession()` established — `withStore(Store $store, callable $callback):
mixed`, swapping the store in for the callback's duration and restoring the
  previous one in a `finally` — not a setter. Same testability pattern, same
  guarantee: a test can't leak its fake into whatever runs next.
- Backing table is deliberately boring: `key TEXT PRIMARY KEY`,
  `value TEXT` (JSON), plus `created` / `updated` timestamps maintained by the
  store (cheap, and useful the first time anyone asks "when did this row
  appear?").
- The database file and schema are created lazily on first write — a site
  that never touches the store never grows a `storage/store.sqlite`.

## Agent legibility

A SQLite file is the least legible artifact in a project whose pitch is
"agents read the same files humans do." Mitigate rather than avoid:

- Console commands in the `reindex` / `cache:clear` / `session:gc` family:
  `garner store:list [prefix]`, `store:get <key>`,
  `store:set <key> <json>`, `store:remove <key>` — making the store
  inspectable and scriptable without SQL.
- A note in `llms.txt` telling agents the store exists, where it lives, and
  how to inspect it (the commands above, or `sqlite3` directly).

## Relationship to sessions

Kept decoupled. Sessions shipped with a file-backed `SessionStore`
(see `sessions-next-steps.md`); a SQLite-backed session store _could_ later be
an implementation of that interface (sessions are keys with expiry), but
neither feature waits on or requires the other. Recording the possibility is
enough.

The name `Store` stands despite `SessionStore` existing — decided in review.
They are different seams: `SessionStore` is an internal persistence interface
actions never touch; `Store` is the userland-facing container. Namespaces and
call sites disambiguate in code; the occasional prose ambiguity is livable.

## Explicitly out of scope for v1

- Queries into JSON values (`where()`, indexes on fields, joins) — the
  key-value boundary above.
- TTL / expiry on keys. Nothing in the motivating use case expires; sessions
  handle their own lifetime.
- Atomic read-modify-write (`update($key, callable)`). The per-item key model
  removes the need for the motivating case (appending to a list); defer until
  a real consumer has a genuinely contended mutable key.
- Multiple named stores / files. One store per project until proven
  insufficient.

## Open questions

1. **Placement.** The class name (`Store`), accessor (`store()`), and method
   surface are decided; the namespace (`Garner\Store\Store` vs
   `Garner\Core\Store` — `Session` lives in `Core`) and the file name
   (`storage/store.sqlite`) remain provisional.
2. **Timestamp exposure.** `created` / `updated` columns are proposed as
   store-maintained; whether and how they surface in the read API (part of
   `items()`? a separate accessor? not at all in v1?) is undecided.
3. **Prefix semantics.** Plain `LIKE 'prefix%'` string matching vs a blessed
   separator (`:`) with namespace-aware helpers. Leaning plain prefix — the
   separator stays a documented convention, not an API concept.
4. **`items()` on a large namespace.** Fine for hundreds of subscribers;
   worth a documented caveat (or a `count()` nudge) rather than pagination
   machinery in v1.

## Near-term next steps

1. Confirm scope (this doc) and the open questions above. ✅
2. Implement `Store` with the lazy SQLite backing, `Application::store()` /
   `withStore()`. ✅
3. Migrate the prototype notify-me action from its current storage to
   `add("email:$hash", …)` — the real-flow proof, same as the action layer's
   step 5. (Lives in the consuming site's own repository, not here.)
4. Add the `store:*` console commands. ✅
5. Tests: add/set/get/has/remove round-trip, `add()` returning `false` on
   duplicate, prefix listing as Collection, lazy file creation, JSON round-trip
   fidelity. ✅
6. Document in README + `llms.txt` once shipped. ✅

## Decided (2026-07-05, during implementation)

1. **Placement:** `Garner\Core\Store`, following `Session`'s precedent —
   the two generic containers live side by side, and a one-class `Garner\
Store` namespace earned nothing. The file is `storage/store.sqlite`,
   overridable via `app.store.path` (absolute, or relative to the project
   root — the same shape as `app.session.path`).
2. **Timestamp exposure:** not surfaced in v1. `created` / `updated` are
   maintained on every row (visible via `sqlite3`) but no read API returns
   them; a real consumer can motivate the shape later.
3. **Prefix semantics:** plain string prefix, always matched literally and
   case-sensitively. Implemented as a `substr()` equality rather than
   `LIKE`, because SQLite's `LIKE` is ASCII case-insensitive by default
   (`items('email:')` would also have matched `Email:…` keys, breaking
   consistency with case-sensitive key equality) and its wildcards would
   need escaping. The `:` separator stays a documented convention, not an
   API concept.

   Revisited post-implementation (2026-07-05): should the namespace be a
   first-class parameter instead — `get('email', $id)`, `count('email')`,
   a `(namespace, key)` schema? That would make namespacing structural
   (no mistyped-prefix footgun: `items('email')` matching `emails:…`) and
   matches how the store is actually used; Deno KV's key-parts model is
   respectable precedent. Kept as is, for three reasons. First, the
   signature collision: `get(string $key, mixed $default = null)` matches
   `Session` exactly, and a namespace-first `get()` either steals the
   default's position or forces three positional parameters on every
   call. Second, every key becomes mandatorily two-part — singletons like
   a site setting would need an invented namespace or a default-namespace
   concept. Third, the flat string is the more primitive model: prefixes
   give arbitrary hierarchy depth for free (`email:pending:<hash>`),
   while a two-column schema bakes in exactly two levels and pushes
   deeper structure back into convention anyway. If prefix ergonomics
   hurt in practice, the recorded escape is a scoped view —
   `$store->space('email')` returning the same surface with keys
   auto-prefixed — which layers on the current schema with no migration.
4. **`items()` on a large namespace:** documented caveat plus the
   `count()` nudge (in the class docblock, README, and `llms.txt`); no
   pagination machinery.

Two implementation notes beyond the proposal, both from review passes on
2026-07-05:

- **Shared-host filesystem hardening** follows `FileSessionStore`, because
  store values are site data, possibly personal (the motivating case
  stores email addresses). The file is chmod'd 0600 on each process's
  first write — not only on creation, so a file pre-created empty by
  tooling, orphaned by a crash between connect and chmod, or first touched
  through the read path self-heals. A chmod failure means this user
  doesn't own the file — most likely a writable file another local user
  planted, the regular-file sibling of the symlink attack — and the write
  fails rather than putting site data where the file's owner can read it;
  the one tolerated failure is a file already owner-only (ACL-managed
  filesystems can fail chmod while the mode is fine). A storage directory
  Garner creates is chmod'd 0700 (a
  pre-existing one keeps the operator's permissions), and the store
  refuses to open its file through a symlink — on a world-writable
  directory another local user could otherwise pre-create `store.sqlite`
  as a link and redirect writes (and the chmod) to a path they chose. The
  symlink check sits on the single connect path, so reads and writes are
  both covered, and a dangling link is refused rather than having its
  target created — including on reads, where it would otherwise pass for
  "nothing stored" and lie dormant until the first write.

- **Reads tolerate a schemaless file.** A concurrent first write opens a
  brief window where the file exists (connect creates it) but the store
  table doesn't yet; an empty pre-created file has the same shape. The
  read path checks `sqlite_master` before querying and answers "nothing
  stored" until the table exists, instead of surfacing a "no such table"
  error for traffic racing the first write.
