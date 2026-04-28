# Garner CMS Review Issues

This document records concrete issues found during a repository review on 2026-04-13.

It is intentionally different from [docs/known-issues.md](docs/known-issues.md):

- `docs/known-issues.md` tracks accepted and already-understood product limitations
- this file records current code and contract findings that should be addressed

## Verification

These checks were run during the review:

- `composer test` - passed
- `vendor/bin/phpstan analyse --memory-limit=1G` - passed
- `npm run lint` - passed

## Findings

### 1. Path index rebuild is destructive instead of atomic

Severity: High

Files:

- [backend/src/Content/PathIndexer.php](backend/src/Content/PathIndexer.php)

Observed behavior:

- the rebuild path recreates schema before replacement rows are fully validated
- the live tables are dropped in `createSchema()`
- the write transaction starts later in `writeIndex()`
- duplicate public paths or parent cycles can fail the rebuild after the previous index is already gone

Why it matters:

- a bad content state can turn an index rebuild into an outage instead of a rejected update
- the system loses the last good derived index even though the new one was never successfully built

Suggested fix:

- build the next index in a temporary SQLite file or temporary tables
- validate and commit the full replacement first
- swap the live index only after the new index is complete

### 2. Canonical content persistence does not enforce key path and status invariants

Severity: Medium

Files:

- [backend/src/Content/PageRepository.php](backend/src/Content/PageRepository.php)
- [backend/src/Content/PathIndexer.php](backend/src/Content/PathIndexer.php)

Observed behavior:

- page save normalizes `slug` only by trimming outer slashes
- repository save accepts any string `status`
- path derivation later treats anything except `null` and `draft` as public
- a manually edited JSON file or a future non-Studio writer could create multi-segment slugs or unsupported statuses

Why it matters:

- Studio-side validation is not enough if CLI flows, fixtures, installers, or manual edits write canonical JSON
- invalid canonical content can silently produce broken or surprising public routing

Suggested fix:

- validate canonical page invariants in the repository or a shared write service
- explicitly restrict `status` to the supported set
- explicitly reject slugs that contain nested path separators

### 3. Page traversal and path resolution do more repeated work than necessary

Severity: Medium

Files:

- [backend/src/Site/Pages.php](backend/src/Site/Pages.php)
- [backend/src/Content/PathResolver.php](backend/src/Content/PathResolver.php)

Observed behavior:

- `Pages::all()` reloads the full repository each time it is called
- `childrenOf()` and `indexOf()` recurse through collections built from repeated full loads
- `makePage()` asks the path resolver for each page path independently
- the path resolver opens a new PDO connection on each lookup

Why it matters:

- current behavior is acceptable for a small tree but scales poorly
- Studio detail and list queries will pay this cost repeatedly as content grows

Suggested fix:

- cache the page collection once per request or query object
- precompute a path map instead of doing per-page path lookups
- reuse a single PDO connection per resolver instance

### 4. Installed-site config loading does not match the documented layout

Severity: Medium

Files:

- [boot/app.php](boot/app.php)
- [docs/architecture.md](docs/architecture.md)

Observed behavior:

- the architecture document describes installed-site config under `site/config/`
- bootstrap currently loads package defaults from `backend/config/` and project overrides from root `config/`

Why it matters:

- maintainers and future installers do not currently have one stable contract
- doc/code drift at bootstrap boundaries tends to create setup and packaging mistakes later

Suggested fix:

- either update the documented installed-site layout to use root `config/`
- or change bootstrap to also merge `site/config/` as documented

## Secondary Observation

There is also a smaller contract mismatch between maintainer guidance and runtime behavior:

- [AGENTS.md](AGENTS.md) says action scripts may return an array, a response object, or `null`
- [backend/src/Core/Router.php](backend/src/Core/Router.php) currently requires actions to return an array

This is not as urgent as the findings above, but the contract should be made consistent before more action types are added.

## Excluded From This File

These were not recorded as findings here because they are already explicitly tracked in [docs/known-issues.md](docs/known-issues.md):

- fixed `/studio` Studio prefix
- non-root installation support
- incomplete blueprint validation
- missing `file_list` implementation
