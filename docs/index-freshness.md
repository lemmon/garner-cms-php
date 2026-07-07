# Routing index freshness

> Status: **implemented.** The `scan` / `locked` freshness model works, and
> engine/schema changes now self-heal via a `schema_version` marker (below) —
> closing the gap this doc originally recorded.

This is a design + decision record, distinct from `brainstorming.md` (an early,
fluid idea log). It expands on the "Routing index" section of the README, which
covers the basics: routes resolve through a derived SQLite index at
`runtime/index.sqlite`; the files are canonical and the index is a rebuildable
cache; freshness follows `app.debug` and is overridable with `app.index.mode`
(`scan` / `locked`); rebuild manually with `php bin/garner reindex`.

## Two kinds of staleness

The index can fall behind for two unrelated reasons, and they want different
answers:

1. **Content changed** — files added / edited / removed (at deploy time, or at
   runtime if Garner writes content).
2. **The engine changed** — a Garner upgrade alters the index *structure* (e.g. the
   `endpoint` column added on 2026-07-01 for route endpoints).

Guiding principle: **auto-heal where Garner has a cheap signal; require an explicit
trigger only where it cannot detect the change without paying to scan.**

## Engine / schema staleness — closed

The stored fingerprint is **content-only** (`dir:mtime` pairs), so an upgrade that
changes the schema while the content is byte-identical would not be detected by the
fingerprint alone:

- **`locked`** used to rebuild only when the index file was missing → served the
  old-schema index → a query referencing new columns failed (e.g. `AND endpoint = 0`
  → "no such column") → 500.
- **`scan`** used to find a matching fingerprint (content unchanged) → skipped the
  rebuild → the **same 500, even in development**.

### The fix

`ContentIndex` stores a `schema_version` in the index `meta` table alongside the
content fingerprint, and bumps a `SCHEMA_VERSION` code constant whenever the schema
changes (new/removed/renamed columns or tables). On every `ensureFresh()` call, in
**both** modes, the stored version is compared to the current constant; a mismatch
forces a rebuild regardless of whether the content fingerprint still matches. An
index built before the marker existed simply has no `schema_version` → reads as a
mismatch → rebuilt once, so pre-existing indexes self-heal automatically with no
manual step. Covered by `tests/IndexFreshnessTest.php`, which hand-crafts a
pre-`schema_version` index (missing the `endpoint` column too) and asserts both modes
heal it instead of throwing.

The only remaining manual lever is `php bin/garner reindex`, still useful for
warming the index ahead of the first request (e.g. a deploy hook).

## Content staleness — by environment

There is no cheap, universal way to auto-detect arbitrary *external* file edits:
directory mtimes do not reflect edits to nested files or file contents, so detection
means walking the tree. This is a deliberate performance / correctness tradeoff,
expressed through the mode:

- **Development (`scan`)** — rescans the tree each request. Automatic.
- **Production (`locked`)** — trusts the built index; run `php bin/garner reindex` on
  deploy. A deploy is a discrete event and reads stay O(1). It fits naturally as a
  post-deploy build step in any deploy hook. The compiled Twig cache
  is locked the same way (`auto_reload` off outside debug), so the full refresh is
  `garner cache:clear && garner reindex`.
- **Small sites** — set `app.index.mode = scan` in production too. The tree walk is
  negligible for a handful of directories, and content stays auto-fresh with no
  reindex step.
- **Runtime content writes (future)** — when Garner mutates its own content
  (publishing, form-created pages), that write path should invalidate / rebuild the
  index inline, since Garner owns the write and therefore has the signal. Not needed
  until those features land.

## Summary

| Staleness cause | Cheap signal? | Today |
| --- | --- | --- |
| Content edit, dev (`scan`) | yes (rescans) | auto |
| Content edit, prod (`locked`) | no (needs a scan) | reindex on deploy — or `scan` for small sites |
| Runtime content write | yes (owns the write) | n/a yet (future: inline invalidate) |
| Engine / schema change | yes (version) | auto-heal via `schema_version`, either mode |
