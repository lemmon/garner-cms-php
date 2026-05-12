# Garner CMS Vision

## Why Build It

This is technically worth building if Garner stays focused on a small number of structural improvements instead of trying to replace every existing CMS pattern at once.

The strongest reasons to build it are:

- legacy flat-file content formats are optimized for hand editing more than modern editor-driven editing
- path-as-filesystem-tree makes renames and large-site traversal more expensive than they need to be
- package and plugin installation can be cleaner
- the CLI should be a first-class core surface, not a separate companion
- the blueprint model can be simplified without losing flexibility

If Garner solves those issues well, it already has a real technical identity.

## North Star

Garner should be a file-native, template-first CMS for small and medium PHP sites:

- content lives in plain files inside the project
- the public site renders with Twig templates by default
- Garner Studio is a separate SPA talking to JSON APIs
- Garner Studio should live at `/studio`
- installation stays Composer-friendly and shared-host-friendly
- runtime performance does not depend on recursively walking the content tree on every request

## What Garner Should Keep

- server-side rendering with Twig as a first-class model
- blueprints as an authoring schema for editing UI and write-time validation
- simple project layout for site builders
- local files for content and assets
- minimal infrastructure requirements

Twig should be Garner's template engine. Projects that use Garner in a more API-oriented way can rely on controllers or API routes instead of requiring a separate rendering mode.

## What Garner Should Change

### 1. Canonical Content Format

Use canonical JSON files for editor-managed content.

Reasoning:

- editors mostly use the Studio UI, not raw content files
- JSON is standard, machine-friendly, easy to validate, and easy to serialize consistently
- stable JSON output avoids the custom parsing and edge cases of a bespoke TXT format

Garner should still keep content files readable:

- pretty-printed JSON
- stable key ordering
- explicit field payloads
- no hidden syntax tricks

### 2. Content Identity and Storage Shape

Do not model the page tree as nested page directories.

Instead:

- every entry gets a stable generated ID
- one JSON document represents one entry
- tree, visibility, and ordering are stored in metadata (`parent_id`, optional
  `slug`, `status`, and listed-page `sort`)
- URLs are derived from indexed relationships, not from directory nesting

UUID v4 is the core default for entry IDs, but the generator is a configurable application-level dependency. Page identity must stay independent from slugs regardless of the selected generator.

This makes page moves and slug changes much cheaper, keeps identity stable even when the visible URL changes, and still allows pages to fall back to their generated ID when no custom slug is needed.

### 3. Runtime Lookup Strategy

Do not treat the filesystem as the query engine.

Garner should use:

- JSON files as canonical source
- SQLite as the derived local index

That gives you:

- fast path resolution
- fast listings and tree traversal
- room for search and filtering
- a clean recovery story because the index can always be rebuilt from files

The on-disk shape should stay simple:

- one generated-ID directory per page
- one `+page.json` file per page
- page-owned files stored next to the page document

That keeps ownership obvious without making cross-page file usage impossible.

File ownership and file accessibility should be separate concepts:

- a file has one owning page
- any page can reference that file by file ID
- SQLite indexes those references for fast lookup and safe deletion checks

### 4. Distribution and Package Hygiene

Installed projects should not contain both `vendor/` and a separate bundled runtime directory.

Target behavior:

- Garner core ships as a Composer package
- installed project code depends on `vendor/garner/cms`
- local project code stays in `site/`
- third-party plugins remain in `vendor/`
- local plugins remain in `site/plugins/`
- no mirrored `site/plugins/composer-plugin` directory

Directory responsibilities should also be strict:

- `site/` is source code and configuration only
- `storage/` is persistent app state
- `runtime/` is disposable derived state like caches, sessions, thumbnails, and indexes

That avoids the confusion of mixing caches and generated folders into `site/` and makes `.gitignore` rules predictable.

The CLI should ship with Garner core as well.

Target behavior:

- core maintenance commands come from the main Garner package
- installed projects do not need a separate CLI package for fundamental operations
- project and plugin code can extend one core CLI surface
- the CLI should be usable by LLM-driven workflows as the preferred automation surface
- installed projects should ship a root `llms.txt` that explains Garner usage to external LLMs building the site, not Garner core internals

### 5. Blueprint Model

Keep blueprints, but replace the fields/sections split with a single schema tree.

The schema should express:

- form controls
- collections and listings
- layout containers
- tabs and grouping

Those are different node kinds in one model, not different top-level concepts with different rules.

Blueprints should matter at authoring time, not as the semantic runtime truth for public rendering.

They should define:

- Studio editing UI
- defaults
- write-time validation
- Studio schema structure

They should not force the public runtime to treat every value as a magical field object.

For validation, Garner can deliberately standardize on `lemmon/validator`.

That fits this architecture well because:

- blueprint rules can compile into validator rules at write time
- API inputs can use the same validation engine
- runtime rendering remains independent from blueprint validation

Because the library is still evolving, Garner should wrap it behind a small internal adapter/compiler layer instead of scattering raw validator calls across the codebase.

### 6. Runtime Value Model

Runtime code should treat content as plain data first.

That means:

- JSON primitives stay as normal PHP values after decoding
- blueprints are not required to render a page successfully
- explicit helper/factory conversions apply semantics where the site code asks for them
- wrong conversions should fail early instead of silently coercing

This is a better fit for typed PHP than universal field coercion.

### 7. PHP Ecosystem Strategy

Garner should not fight modern PHP or recreate generic helper libraries that already exist.

Preferred approach:

- use native PHP features where they are already good enough
- use mature standalone packages for generic collection and string manipulation
- use `lemmon/validator` for validation concerns
- reserve Garner classes for CMS-specific concepts, not for cloning `Str`, `Arr`, or generic collection APIs

In practice this means it is reasonable to depend on libraries such as Laravel's standalone collection utilities and established string helpers instead of maintaining a homegrown CMS-specific utility surface.

The line should be:

- generic data traversal: ecosystem library
- generic string transforms: ecosystem library
- validation engine: `lemmon/validator` behind a Garner adapter
- page routing, content repositories, file ownership, blueprint parsing: Garner code

## Opinionated V1 Boundaries

Garner v1 should stay intentionally narrow.

Include:

- single-site installs
- single-language content
- Twig rendering by default
- Studio authentication
- JSON content files
- SQLite indexing
- page-owned files with global references
- strict `storage/` vs `runtime/` separation
- explicit typed helpers/factories instead of universal field coercion
- mature standalone PHP utility libraries instead of homegrown generic helper layers
- `lemmon/validator` as the validation engine behind a thin Garner adapter
- core CLI commands shipped with Garner itself
- local and Composer plugin discovery

Defer:

- multisite
- multilingual content
- collaborative editing
- database backends beyond SQLite
- broad compatibility promises with other CMS content models
- broad plugin ecosystem compatibility

## Technical Position

The most important product choice is this:

Garner should be file-native, not filesystem-shaped.

That distinction matters. Keeping files as the source of truth preserves portability and simplicity. Breaking the hard dependency between URL tree and directory tree solves several long-term problems at once.

## Practical Warning

The main technical risk is over-scoping the first version.

If you start by chasing:

- complete feature parity with existing CMSes
- a complex Studio before the content model is stable
- a large plugin API before core behaviors are settled

then the project will absorb a lot of effort before its differentiators are proven.

The technically strongest first version is smaller:

- JSON content documents
- stable generated identity with configurable ID generation and UUID v4 as the default
- SQLite path index
- Twig rendering
- Studio shell plus basic CRUD

That is enough to validate the architecture without committing to years of compatibility debt.
