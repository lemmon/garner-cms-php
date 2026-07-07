# CLI expansion — proposed next steps

> Status: **proposal only.** Nothing below is implemented, scheduled, or set in
> stone. This is a thinking document in the shape of the other `*-next-steps.md`
> records: candidate commands to weigh, with the reasoning that would justify —
> or kill — each one. Decisions get recorded here as they're made.

## Context

The CLI today covers derived-cache maintenance (`reindex`, `cache:clear`),
integrity (`validate`), one scaffolding operation (`page:create`), session
sweeping (`session:gc`), and store inspection (`store:list` / `store:get` /
`store:set` / `store:remove`). That set grew feature by feature — each command
shipped alongside the subsystem it serves.

What it does **not** yet cover is the middle of a page's life: everything
between "scaffold it" and "validate the tree." `docs/brainstorming.md` sketched
this from the start — "creating, moving, copying, and deleting pages safely"
and "operations that must update references or redirects atomically" — and the
agent-first thesis makes it concrete: an agent asked to "move the pricing page
under /products" today has to hand-roll `mv`, remember to `reindex`, and hope
it didn't break an id reference. Those are exactly the boilerplate-and-mistakes
operations the CLI exists to absorb.

The bar from `brainstorming.md` stands for every candidate below: direct file
edits stay a first-class workflow, so a command must earn its place by
preventing boilerplate, mistakes, or inconsistent project state — not by
wrapping something a person or agent can already do safely with a text editor.

## Ground rules (carried over, not new)

Every candidate below inherits the "CLI requirements for agents" list already
established in `brainstorming.md` and honored by the shipped commands. Not
restated here — see that doc for the authoritative list, so the two don't
drift when a rule changes.

## Candidate commands — pages

### `page:list [path] [--drafts] [--json]`

Read-only listing of the page tree (route path, id, title, draft state),
optionally scoped to a subtree. The data already exists in the route index;
this surfaces it without SQL, the same way `store:list` made `store.sqlite`
inspectable. For an agent this is the cheap orientation pass before editing —
today it walks directories and opens entry files one by one.

### `page:show <route-or-id> [--json]`

Inspect one page: resolved metadata, content files and the `content.*` names
they map to, assets and sidecars, the template that would render it. Accepting
an id as well as a route path gives `findById()` a CLI twin — useful when an
agent holds a reference and wants to know what it points at.

### `page:move <from> <to> [--dry-run] [--json]`

The flagship candidate, and the reason this doc exists. Moving a page is the
operation Garner's model was _designed_ to make safe (identity lives in `id`,
not the path) but the mechanics still have sharp edges a bare `mv` ignores:

- the destination must not already be a routable page (collision check);
- children move with the directory — the command should say so explicitly
  (`--dry-run` listing every route that changes);
- the index must be rebuilt afterwards, one way or another — whether the
  command does this inline or leaves it to a follow-up `reindex` is Open
  Question 2 below;
- id-based references keep working by design; the command's report is where
  that promise gets stated per-move rather than assumed.

Open sub-question: whether a move should be able to _record a redirect_ from
the old route. `brainstorming.md` asks "How should route moves and redirects
be recorded?" and there is no redirect facility in core yet — the honest v1
likely moves the directory and reports the old/new routes, leaving redirects
to a future feature rather than inventing one inside a CLI command.

### `page:copy <from> <to> [--dry-run] [--json]`

Copy a page directory — with one non-negotiable difference from `cp -r`: the
copy gets a **fresh id** (and its descendants likewise). A verbatim copy
duplicates ids and the next index build fails uniqueness; this footgun is the
entire justification for the command. Content files and assets copy verbatim.

### `page:delete <route> [--force] [--dry-run] [--json]`

Delete a page directory. The safety margin over `rm -rf`: refuse (without
`--force`) when the page has children, and report — via the index — any other
page whose metadata references the deleted id, so a broken reference is a
stated decision instead of a surprise at render time. Index rebuild timing is
Open Question 2 below, same as `page:move`.

### `page:draft <route>` / `page:publish <route>`

Toggle the `draft` flag without hand-editing JSON. Borderline: editing one
boolean in `+page.json` is exactly the "straightforward local edit" the CLI
promised not to absorb. It earns a slot only as part of scripted workflows
(an agent staging N pages and publishing them together). Alternative shape: a
generic `page:set <route> <field> <value>` — rejected for now; a freeform
metadata setter is a worse editor than an editor, and `draft` is the only
core-owned boolean. Leaning: defer both until a real workflow asks.

## Candidate commands — store

The `store:*` family is nearly complete for its scope; the gaps are parity
and lifecycle, not new capability:

### `store:add <key> <json>`

CLI twin of `Store::add()` — atomic insert-if-absent, exit code distinguishing
"added" from "already present." Today a script that wants uniqueness semantics
has to shell out to `store:get` first and race itself; the whole point of
`add()` was removing that dance, and the CLI should not reintroduce it.

### `store:count [prefix]`

Twin of `Store::count()`. `store:list --json | jq length` answers it today by
loading every value; `count` is the documented nudge for large namespaces and
deserves the same cheap path from the shell.

### `store:export [prefix]` / `store:import`

Dump a namespace (or everything) as JSON lines — `{"key": …, "value": …}` per
line — and load such a dump back. This is the backup/migration story the docs
currently answer with "back up `storage/`": file-level backup is right for
disaster recovery, but moving _data_ between environments (staging subscribers
into production, seeding a test fixture) wants a text format that survives
SQLite version differences and diffs cleanly. Import semantics to decide:
upsert vs add-only (likely a `--replace` flag choosing).

### Removal by prefix

`store:remove` deletes one key. Clearing a namespace (`email:` after a
migration) currently means a shell loop. Options: `store:remove --prefix
<prefix>` (explicit flag, so a bare key stays the safe default) or a separate
`store:clear <prefix>`. Leaning flag-on-remove; either way `--dry-run` should
print what would go.

## Candidate commands — utilities

### `id:generate [--count=N] [--json]`

Emit one or more fresh ids from the project's configured `app.ids.generator`
(Cuid2 by default; may be a Uuid, Ulid, custom class, or callable). This is
the exact primitive `page:create` already calls internally
(`$app->idGenerator()->generate()`) — surfacing it as its own command earns
its place for the same reason `page:copy` needs fresh ids for an entire
subtree: id format is a per-project configuration choice, not something an
agent can see or safely guess from outside. An agent that hand-rolls a UUID
on a project configured for Cuid2 (or a custom scheme) produces a
syntactically-fine but non-conforming id — a mistake this command exists to
prevent, the same class of footgun `page:copy`'s fresh-id requirement is
built on. `--count=N` batches ids for scripted workflows that need several
before writing anything (e.g. seeding multiple store entries in one pass).

A paired "hand out a timestamp" command was considered and doesn't clear the
same bar: `llms.txt` only requires `created` to be a "non-empty timestamp
string when useful," with no app-owned format or timezone convention (no
`app.timezone` config exists) to get wrong. An agent can already produce a
valid one with `date('c')` or its own language's stdlib without knowing
anything Garner-specific — there's no boilerplate or mistake here for a
command to absorb. Left out unless a canonical timestamp format is ever
adopted.

## Adjacent, recorded elsewhere

- **`media:clean`** — garbage-collecting orphaned `public/media/<hash>/`
  copies is open question O2 in `docs/media-handling.md`. It belongs in this
  command family when it lands, but the design question (prune during
  `reindex` vs standalone command vs accept growth) is owned there.
- **Session inspection** (`session:list` or similar) — deliberately **not**
  proposed. Session files are keyed by bearer-token ids and hold per-visitor
  state; a convenience command for browsing them normalizes exactly what the
  0600/0700 hardening exists to discourage. `session:gc` remains the only
  session command.

## Explicitly out of scope

- A scaffolding wizard or any interactive mode — flags only.
- Queries into store values (`store:find --where …`) — the key-value boundary
  from `docs/key-value-store-next-steps.md` applies to the CLI too.
- A generic metadata editor (`page:set`) — see the `page:draft` discussion.
- Redirect management — depends on a redirect facility core doesn't have.

## Suggested order, if and when

1. `page:list` / `page:show` — read-only, low risk, immediately useful to
   agents; they exercise the index without touching content.
2. `page:move` — the highest-value mutation, and it forces a resolution to
   the reindex-timing question (Open Question 2) that every other mutation
   will reuse.
3. `store:add` / `store:count` / `id:generate` — small parity fills.
4. `page:copy` / `page:delete` — same plumbing as move, more edge cases; `id:generate`
   is what `page:copy`'s fresh-id requirement would call internally.
5. `store:export` / `store:import`, prefix removal — when a real migration
   needs them.

## Open questions

1. **Route path vs id addressing** — `page:show` proposes accepting both;
   should the mutation commands too? Moving by id (`page:move <id> <to>`) is
   arguably the more agent-native call. Leaning: routes for v1, ids where
   free.
2. **Inline reindex vs advisory** — should mutating page commands rebuild the
   index themselves (consistent with "Garner owns the write") or print a
   "run `reindex`" reminder? Undecided.
3. **Reference reporting on delete** — "pages referencing this id" requires
   knowing where references live; core has `findById()` but no reverse index.
   A first version may only scan `+page.json` metadata for the id string —
   honest but incomplete. Decide whether partial detection is worth shipping.
4. **Copy id regeneration depth** — fresh ids for the whole copied subtree is
   the safe default; is an opt-out (`--keep-ids`, for moving content between
   projects) ever legitimate, or purely a footgun?
