# Files & media handling

> Status: **first slice implemented** (page-owned files, sidecar metadata, public
> publishing). Several decisions are recorded here as accepted; the open questions
> at the end are deliberately deferred so we can iterate rather than design the whole
> system up front.

This document is a design + decision record, distinct from `brainstorming.md` (which
is an early, fluid idea log). It supersedes the single open question about media in
that document.

## What "media" means here

Any non-content file that lives beside a page entry — an image, PDF, video, font,
download — is a **file asset**. Files are owned by the page whose directory they sit
in, exactly like the page's `+page.json` and content files. They are part of the
route tree, version-controlled with the page, and travel with it when it moves.

```text
routes/about/
├── +page.json
├── main.md          → content.main   (parsed content value)
├── team.jpg         → a file asset
└── team.jpg.json    → optional sidecar metadata for team.jpg
```

Garner is a normal mutating PHP application (the "LAMP" model), **not** a static-site
generator. Publishing files, handling form submissions, and other runtime writes are
expected. A build step or static export may come later as an option, but it does not
shape this design.

## Accepted decisions

### 1. Identity is location; no filename-to-id fallback

A page reaches its own files by name: `page.file('team.jpg')`. Within a directory the
filesystem already guarantees unique names, so a file needs **no global id** by
default, and we do **not** invent one from the filename (that would collide constantly
— every page has its own `cover.jpg`).

- **Page-owned** (the common case): `page.file('team.jpg')`, `page.files()`.
- **Cross-page / shared** (opt-in): give a file a stable `id` in its sidecar and
  resolve it from anywhere. Shared assets (a logo, an author avatar, an OG image) take
  this path, or live in a loose container — see open question O3.

### 2. Sidecar metadata, never auto-created

Metadata for `team.jpg` lives in a sibling `team.jpg.json` (or `.yaml`/`.yml`). Unlike
some flat-file CMSs, Garner does **not** create sidecars automatically — they appear
only when an author needs them, keeping directories quiet and legible. A file with no
sidecar simply has empty metadata.

A structured file whose name matches a sibling asset (`team.jpg.json` next to
`team.jpg`) is recognised as that asset's sidecar: it attaches to the file
(`file.meta()` / `file.get('alt')`) and is **not** loaded into the `content.*`
namespace. A standalone `data.json` with no matching sibling is still a normal content
value.

### 3. `url()` publishes; publishing means "public"

Content lives outside the web root (under `routes/`), so it is not directly
downloadable. Calling `file.url()` **publishes** the file into the public media
directory (`public/media/<hash>/<filename>`, gitignored, rebuildable) and returns its
URL. The web server then serves it directly.

Publishing makes a file **publicly downloadable by anyone holding the URL**, forever.
So `url()` is an act of publication, not access control. Truly private or
access-controlled files must **not** be published — they should be streamed through a
controller that performs the access check (see open question O4).

### 4. Content-hashed, immutable URLs

The published path embeds a short content hash (`xxh128`):
`/media/9f3a.../team.jpg`. This makes the URL immutable and cache-bustable — editing
the file changes its hash, so the URL changes and caches refresh on their own. It also
keeps the URL stable when the owning page moves. The hash is computed from the copied
snapshot, not the live source, so the hash in the URL always matches the published
bytes even if the source is edited mid-request — the invariant is that any file at
`/media/<H>/name` holds bytes whose hash is `H`.

### 5. Publish a copied snapshot, not a symlink

Publishing **copies** the bytes into the hash directory (written to a temp file and
atomically renamed). It deliberately does **not** symlink the live source file: a
hash URL addresses specific content, so a symlink would break the immutability of
decision 4 — editing the source would change the bytes served under an unchanged hash
URL (or dangle if the source moves), so already-rendered or cached HTML would no
longer point at content matching its hash. A snapshot keeps `/media/<hash>/file`
serving exactly the bytes that produced its hash. (Symlinking also tripped a second
problem: `symlink()` failing on a host that disallows it emits a warning that the
error handler turns into a 500.) The media directory is always runtime/disposable and
outside version control; the disk cost of copies is paid back by GC — see O2.

## What the first slice implements

- `Garner\Content\File` — a file-asset value object: `filename()`, `name()`,
  `extension()`, `path()`, `exists()`, `size()`, `modified()`, `mimeType()`,
  `isImage()`, `hash()`, `meta()`, `get()`, and `url()` (publishes on first call).
- `Garner\Content\FileCollection` — a Laravel collection of files keyed by filename,
  with an `images()` filter.
- `Page::file(string $name): ?File` and `Page::files(): FileCollection` — owner-scoped
  access. Both route through one predicate (`Page::isAssetFile`), so the singular and
  plural accessors expose exactly the same set: regular files inside the page directory
  whose extension is not a content format. Reserved names, paths escaping the directory,
  **symlinks** (which could point outside the tree, e.g. at `/etc/passwd`),
  **server-executable extensions** (`.php` and friends, blocked so publishing cannot
  drop runnable code into `public/`), and parsed content files and their sidecars are
  all excluded — those are reached through `content`, never published as assets.
- Sidecar exclusion (decision 2), via the same `Page::isAssetFile` / `isAssetSidecar`
  predicates used by the accessors — so the content loader **and** `TreeValidator`
  classify directory entries identically (no phantom `garner validate` collisions).
  Sidecar extensions are matched case-insensitively, like every other format.
- `Garner\Content\MediaPublisher` — publishes a file into `public/media/<hash>/` and
  returns its URL; wired through `Application::mediaPublisher()`.

## Open questions (deferred — iterate)

- **O1 — Cache that skips PHP.** With a full-page cache / reverse proxy in front, a
  cache hit serves HTML without running PHP, so a lazy `url()` publish never fires and
  the referenced file may be missing. The fix is to make the public URL fully
  deterministic and materialise files at a media endpoint on first request (the same
  mechanism we want for thumbnails), so cached HTML still resolves. Not built yet.
- **O2 — Garbage collection.** Renamed/deleted files and removed pages leave published
  copies and (future) derivatives behind. Content-hash naming makes stale entries
  harmless but disk still grows. Decide between pruning during `reindex`, a
  `media:clean` command, or accepting growth.
- **O3 — Loose / site-level assets.** Files in a non-page container (a directory with
  no `+page.json`, e.g. `routes/assets/`) belong to no page. Decide the addressing
  API: path-addressed (`site.file('assets/logo.svg')`) and/or id-addressed
  (`site.findFile(id)`). Note: a page's directory at the tree root is also the home
  page's directory, so root-level loose files are inherently home-owned.
- **O4 — Private / streamed files.** A first-class streaming lane for files that must
  not be published — a `RenderedResponse::file()` (correct content type + HTTP range
  support) returned from a controller after an access check.
- **O5 — Thumbnails / transforms.** On-demand derivatives generated at a media
  endpoint. Two viable gates, both of which close the `?w=9999` denial-of-service hole:
  a persisted job/manifest file (inspectable, fits the agent-first thesis) or a signed
  transform URL (stateless). Undecided.
- **O6 — More formats and conveniences.** Whether `.txt` should ever be an asset
  rather than content; a convenience like `file.alt()`; richer image metadata
  (dimensions) — currently hand-typed into every image sidecar (`width`/`height`
  in `hero.webp.json`), see `brainstorming.md` 2026-07-23. Was low priority;
  worth reconsidering now that a consumer site has hit it.
- **O8 — Case-mismatched sidecar basenames.** `file()` now matches the exact on-disk
  name, and `File::sidecarPath()` matches the asset basename exactly, but
  `isAssetSidecar()` (loader/validator) still leans on `is_file`, which is
  case-insensitive on macOS/Windows. So a sidecar named with different basename casing
  than its asset (`PHOTO.JPG.JSON` next to `photo.jpg`) can be skipped from content yet
  not found as metadata on those filesystems. Exotic (needs deliberate mis-casing); the
  fix mirrors `file()`'s exact-entry check inside `isAssetSidecar`.
- **O7 — Defence in depth for the media root.** The executable-suffix blocklist is the
  code-level guard (every dot-separated suffix is checked, so `avatar.php.jpg` is
  refused too); the deeper fix is ensuring `public/media/` never executes scripts at
  all (a generated `.htaccess` / server snippet, ties into deployment hardening). Also
  unhandled: client-side execution from `.svg`/`.html` served on-origin (a stored-XSS
  vector) — mitigated by `Content-Disposition`/CSP or sanitising, not by blocking, since
  SVG-as-image is a primary use. Decide if/when these matter.
