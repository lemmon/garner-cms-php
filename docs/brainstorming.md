# Garner brainstorming

> [!IMPORTANT]
> Everything in this document is fluid. These ideas are first-draft general principles, not settled requirements, architecture decisions, or compatibility promises. We expect to test, revise, replace, or discard them as Garner develops and we learn what works.

This document is a running record of early product thinking. Examples, names, formats, commands, and conventions are illustrative unless a later decision record explicitly marks them as accepted.

## 2026-06-19 — Finding Garner's edge

Garner began as an attempt to build a PHP CMS in the spirit of Kirby. The initial direction was effectively another flat-file CMS in that mold. That alone is not a meaningful product edge.

The more promising direction comes from how content work is changing: a growing share of writing, editing, structuring, and maintenance now happens through LLMs and AI agents. A new CMS should treat agents as first-class collaborators rather than adding an AI feature to a conventional administration panel.

Many projects—especially smaller sites—do not need an administration panel at all. For those projects, an agent-friendly content layer may be the primary interface. A graphical administration panel should be optional, not the center of the system.

### Working product thesis

Garner is a content system designed for humans and AI agents to work with the same content safely and seamlessly.

Its differentiation should come from the content model, interfaces, and workflows that make agent-driven content operations natural—not merely from being another flat-file PHP CMS.

### Early principles

- Agents are first-class clients, not an integration added later.
- Content remains understandable and editable without Garner-specific tooling.
- The administration panel is optional and can be introduced only when a project needs it.
- Small sites should remain simple to create, operate, and deploy.
- Agent actions should be inspectable, attributable, and reversible.
- Architecture should follow the product advantage; cleaner internals alone are not the product.

### Questions to explore

- What does an agent-native content interface look like: files, a CLI, an API, MCP, or a combination?
- What is the source of truth for content?
- How should Garner expose schemas and project context so an agent can make valid changes?
- How are validation, previews, drafts, approvals, and publishing handled?
- How should an agent describe its intent and record the changes it made?
- What permissions and safeguards are needed for autonomous content operations?
- Can Garner work without a running application or database for the smallest sites?
- Which responsibilities belong to the CMS core, and which belong to optional adapters or interfaces?
- Is PHP still the right implementation choice once the product is defined around agents rather than around a traditional CMS runtime?

### Current non-goal

Do not begin by recreating Kirby feature-for-feature. Compatibility or familiar concepts may eventually be useful, but they should not define Garner's direction.

## 2026-06-19 — Design influences and different trade-offs

Kirby is a well-loved, mature flat-file CMS, and it shaped a lot of Garner's early thinking. Garner is aiming at a different goal—content that humans and agents work with side by side—so it makes some different trade-offs. The contrasts below are about fit for that goal, not shortcomings in Kirby.

### Keep content independent of any administration panel

Kirby pairs flat-file storage with a rich administration panel, and much of its workflow is built around that panel.

Garner keeps content independent from any administration interface. A panel can be one client of the content system, but it should not dictate the storage format or core model.

### Prefer standard, composable content formats

Kirby stores content in its own text format, with structured data often expressed as JSON within it. That works well inside Kirby's own tooling.

Because Garner wants content to be legible to general-purpose tools and agents without product-specific knowledge, it leans the other way:

- favor formats people and machines already understand;
- let general-purpose editors and tools read the files directly;
- keep structured data structured rather than embedded inside another format;
- avoid requiring product-specific syntax to read or change content safely.

Garner should prefer established, composable formats with mature parsers and tooling.

### Keep ordering separate from storage paths

Kirby represents page order with numeric directory-name prefixes, so reordering a page renames its directory and changes its path.

Garner keeps ordering metadata separate from stable page identity and storage paths, so reordering a page stays a small, semantic change in version control.

### Make page type explicit

Kirby derives the template or page type from the content filename.

Garner represents page type explicitly in content metadata or a schema declaration. A page keeps a stable identity regardless of its type, template, title, slug, or position, and changing its type does not rename files.

### Make the system self-describing for agents

A defining goal for Garner is to be legible to an agent. An agent should be able to discover:

- the available content types and their schemas;
- the relationships between content items;
- validation and publishing rules;
- available operations and their consequences;
- project-specific instructions and terminology;
- the current state and history needed to make a safe change.

### Derived design constraints for Garner

- Use stable identifiers and stable storage paths.
- Keep order, slug, type, and presentation separate from identity.
- Use standard formats that humans, agents, and existing tools can parse.
- Avoid nested serialization and embedding one content format inside another.
- Make the core content model independent of an administration panel.
- Provide a machine-discoverable schema and capability interface.
- Optimize version-control diffs for semantic changes rather than storage mechanics.

## 2026-06-19 — Filesystem routing inspired by SvelteKit

SvelteKit offers a useful model for making application structure visible through ordinary files and directories. Garner could combine that clarity with the strengths of a flat-file CMS.

### One predictable route entry point

SvelteKit uses `+page.svelte` as the recognizable entry point for a page route. Garner could use `+page.json` as the content entry point:

```text
content/
├── +page.json
├── about/
│   └── +page.json
└── contact/
    └── +page.json
```

The filename is stable and has one meaning: this file defines the content for the route represented by its directory. Unlike deriving the page type from a filename, `+page.json` would not encode the content type. Type and other metadata would be explicit inside the document.

```json
{
  "$schema": "../../schemas/page.json",
  "id": "01JABC...",
  "type": "page",
  "title": "About"
}
```

This makes routes easy to recognize and gives editors, validators, and agents a standard structured format.

### Filesystem-defined routes

The directory tree can define the public route tree:

```text
content/blog/+page.json          → /blog
content/blog/archive/+page.json  → /blog/archive
```

Dynamic segments could follow SvelteKit's bracket convention:

```text
content/blog/[slug]/+page.json   → /blog/:slug
```

This creates a compact routing vocabulary that is visible without loading Garner or reading a separate routing configuration.

### Route is not identity

Filesystem routing introduces a deliberate distinction:

- The directory path determines where content is routed.
- The `id` inside `+page.json` determines what the content item is.
- The `type` determines its schema and capabilities.
- Ordering is separate metadata and does not alter directory names.

Moving a page may change its route and file path, but it should not change its identity. References should therefore use stable IDs rather than filesystem paths wherever practical.

### Dynamic routes need a content-resolution model

A dynamic route such as `[slug]/+page.json` describes a route pattern, but it does not by itself answer where individual entries live or how `slug` resolves to one of them. Possible models include:

1. Each concrete entry has its own route directory, and bracket routes are used only for application-controlled resolvers.
2. A dynamic route points to a collection stored elsewhere and resolves entries by a field such as `slug`.
3. Entries live below the dynamic directory in a Garner-defined collection layout.

This should be decided before the filesystem convention becomes a storage contract.

### Questions to explore

- Does every route have exactly one `+page.json`, or can a route contain related content documents?
- Where does long-form prose live when JSON string escaping becomes unfriendly: inside JSON, in a referenced Markdown file, or in a sibling `+page.md`?
- Does `+page.json` contain content only, or can it also declare loaders, queries, redirects, and access rules?
- How does a dynamic segment discover and validate its parameter source?
- How are optional, rest, and grouped routes represented?
- Can a page exist without a public route?
- How should route moves and redirects be recorded?
- Which conventions should Garner share with SvelteKit, and which would create misleading expectations?

### Working direction

Use a filesystem route tree and a single, predictable `+page.json` entry point while keeping identity, content type, ordering, and relationships explicit and independent of filenames.

## 2026-06-19 — Initial product shape

Garner should combine a predictable route entry point with flexible, file-based content and a small set of useful automation tools.

### Route metadata in `+page.json`

Every page directory has one `+page.json` containing its identity and route-level metadata. Content does not need to be embedded in this file.

```json
{
  "$schema": "../../schemas/page.json",
  "id": "994d220e-d6e5-4e78-a445-ddca32720212",
  "type": "article",
  "createdAt": "2026-06-19T16:00:00Z",
  "title": "Example article",
  "template": "article.twig"
}
```

The metadata contract should remain small and explicit. Project schemas can add fields required by a particular content type.

### Multiple content files compose a page

A page directory may contain any number of content and data files:

```text
content/articles/example/
├── +page.json
├── main.md
├── introduction.md
├── conclusion.md
├── categories.json
└── options.toml
```

This avoids forcing every kind of content into one large document or serializing one format inside another. Each file can use the format best suited to its content:

- Markdown for prose and prose blocks;
- JSON for widely interoperable structured data;
- TOML or INI for configuration-like data;
- additional formats through adapters.

Garner should support a focused set of obvious formats by default and expose an adapter interface for others. Parsed files become named values available to the page renderer. For example, `main.md` could become `content.main` and `categories.json` could become `content.categories`.

The exact mapping rules need to remain predictable. File basename collisions, nested directories, front matter, unsupported files, and parse failures must have defined behavior.

### Twig by default, explicit response handlers when needed

Twig is the default rendering layer for HTML routes. A conventional page can select or inherit a Twig template and render the route metadata plus parsed content files.

Some routes need to return data or non-HTML representations. Garner should also allow a PHP response handler that can produce JSON, plain text, or another explicit response type.

Conceptually:

```text
+page.json → metadata and route configuration
*.md/json/toml/ini → parsed page content
*.twig → default HTML presentation
PHP handler → programmable response when templating is insufficient
```

The PHP handler should return a defined response abstraction rather than relying on arbitrary output buffering. Its contract should cover status, headers, content type, and body.

Questions still to settle:

- Is the handler conventionally named `+page.php`, referenced by `+page.json`, or both?
- Does a Twig template live beside the content or in a shared templates directory?
- How does template inheritance work across nested routes?
- Can one route negotiate multiple representations, such as HTML and JSON?
- What context and services may a PHP handler access?

### CLI as an automation layer

Editing content files directly should remain a supported primary workflow. The CLI exists for operations where automation prevents boilerplate, mistakes, or inconsistent project state.

Creating a page is one such operation:

```shell
garner page:create path/to/page
```

It should create the route directory and a valid `+page.json`, including at least:

- a UUID v4 identifier;
- creation time in a documented machine-readable format;
- required defaults from the selected content type or schema.

The user or agent can then adjust ordinary fields and content files directly.

The CLI should cover common operations, not attempt to become the only interface to Garner. Likely responsibilities include:

- creating, moving, copying, and deleting pages safely;
- scaffolding a project and common structures;
- listing and inspecting pages, schemas, routes, and capabilities;
- validating metadata, content files, references, and route conflicts;
- invoking previews or builds;
- performing operations that must update references or redirects atomically.

Straightforward local edits should not require a command. Updating prose in `main.md`, changing a title, or editing a known JSON field is already easy for a person, script, or agent.

### CLI requirements for agents

To work reliably for both shell users and agents, commands should be:

- non-interactive by default or fully controllable with flags;
- deterministic and safe to repeat where practical;
- able to emit stable structured output, likely with `--json`;
- clear about changed files and validation errors;
- equipped with `--dry-run` for operations with broad effects;
- documented with stable exit codes and discoverable help;
- built from the same domain operations exposed to other interfaces.

### Emerging boundary

Garner owns the route tree, metadata contract, content parsing, validation, rendering context, and safe structural operations. Files remain the direct content interface. Twig, PHP handlers, the CLI, agents, and a possible future administration panel are clients of the same core model.

## 2026-06-19 — Project layout and deployment

Garner should have a conventional default layout while remaining deployable on constrained shared hosting.

### Default directories

```text
project/
├── content/          # Content files and filesystem routes
├── storage/          # Generated and local state, normally excluded from Git
│   └── cache/        # Disposable runtime cache
└── public/           # Public web root
    └── index.php     # HTTP entry point
```

Responsibilities:

- `content/` holds the route tree, `+page.json` metadata, and the files that compose each page.
- `storage/` holds generated, environment-specific, or local state that normally should not be committed. Projects may need selected persistent subdirectories, but `storage/cache/` must always be safe to rebuild.
- `public/` contains the HTTP entry point and assets that may be served directly by the web server.

The project scaffold should provide appropriate ignore rules and writable-directory checks for `storage/`.

### Deployment profiles

The preferred deployment points the server document root at `public/`:

```text
public/index.php
```

Garner should also support hosting where the document root cannot be configured. In that profile, the entry point can live at the project root:

```text
index.php
```

The selected layout should be configurable and scaffolded deliberately rather than detected through fragile runtime guesses.

Running without a separate public directory creates a security obligation: `content/`, `storage/`, configuration, dependencies, and source files must not become directly downloadable. Garner should generate server-specific deny rules where possible, validate the deployment, and clearly warn when it cannot establish that protection. The `public/` layout remains the recommended default.

Questions to settle:

- Which configuration value selects the public-root profile?
- Should the scaffold move files between profiles or generate the selected profile initially?
- Which web servers and shared-hosting configurations receive generated protection rules?
- How can `garner validate` detect accidentally public project files?
- Where do user-uploaded public media live, and how are private media kept outside the web root?

### PHAR distribution

A future Garner release could package the runtime as a PHAR archive. This would make deployment to basic hosting possible by uploading a small project plus one runtime file over FTP, without Composer or shell access on the server.

Potential shape:

```text
project/
├── content/
├── storage/
├── public/
│   └── index.php
└── garner.phar
```

PHAR support is not an initial priority. The architecture should avoid unnecessarily preventing it, but packaging, update strategy, signature verification, PHP extension requirements, and host compatibility can be designed after the core content and routing model is proven.

## 2026-07-02 — Endpoints cannot reference each other's URLs

Surfaced while building `/robots.txt` for the first consumer site. Its `Sitemap:`
line must be an absolute URL, but the sitemap is a route endpoint — invisible to
traversal and `findById` by design — so there is nothing to resolve. The
controller hand-composes `$site->url() . '/sitemap.txt'`.

For author-owned routes this is acceptable (you named the directory, you know the
path), and it is the only place the API currently hands back string concatenation
instead of an accessor. If endpoints multiply, the natural fix is a composition
helper in the `url()`/`path()` family — e.g. `site.url(path)` returning the base
URL joined with a path — rather than making endpoints traversable, which would
reintroduce the tree pollution the endpoint model exists to avoid.

Not planned; recorded so the gap is a decision, not an accident.

## 2026-07-02 — Form submissions / POST handling (assumptions, untested)

> Status: **brainstorm only.** Nothing below is implemented or verified — these are
> working assumptions to be tested and discussed before any of it becomes design.

### Assumption: the existing controller contract is already the form contract

A form's target is just a controller — the page's own `+controller.php` or an
endpoint. PHP parses `application/x-www-form-urlencoded` and multipart bodies into
`$_POST` natively (the LAMP platform is the middleware), so a controller could
branch on the request method today:

- **Return an array** on validation failure → the same page re-renders with
  `errors` / `old` input in template context. Repopulated form, same URL.
- **Return `RenderedResponse::redirect($target, 303)`** on success →
  Post/Redirect/Get, using the redirect support built for canonical paths.
  `303 See Other` is the correct PRG status (client re-requests with GET).
- Validation belongs to `lemmon/validator`, already a dependency.
- The trailing-slash canonicalization uses **308** (method-preserving), so a
  `POST /contact/` survives the canonical redirect with its body intact — this
  was a deliberate reason to prefer 308 over 301.

Believed to work with zero core changes; **not yet exercised by any real form.**

### Open questions (decide before building)

1. **CSRF — the foundation decision.** Garner has no session story. Options:
   real PHP sessions (LAMP-native, heavyweight), stateless signed tokens
   (HMAC + timestamp, no server state), or relying on `SameSite=Lax` cookies for
   content-site forms. Whatever is chosen also answers question 2.
2. **Flash messages / repopulation across the PRG redirect** — needs one-request
   state (session or one-shot cookie).
3. **`Request` niceties** — `method()`, `post()` wrappers for testability;
   `getInput()`/`getPayload()` already cover JSON bodies.
4. **Form-created content** — a POST that writes a page into `routes/` is the
   "runtime content writes" future in `index-freshness.md`: Garner owns the
   write, so it invalidates the index inline. File uploads would flow into the
   page-owned-assets media model.
5. **Named form actions (SvelteKit-style `?/action`) vs. single-controller
   method branching** — branching is the simplicity-bias default until a real
   site proves it insufficient.

First real test candidate: a contact form on a consumer site, built with nothing
but a `+controller.php` and `$_POST`, to validate assumption #1 before touching core.
