# AGENTS.md

Guidance for AI agents and contributors working in this repository.

## Site-building agent guidance

See `llms.txt` for short, practical guidance aimed at LLMs building a site or app
with Garner, rather than changing Garner itself.

## Documentation lives in `docs/`

Project notes, design thinking, and brainstorming are kept in `docs/`. These are
working documents: ideas are provisional and may be revised, replaced, or dropped as
Garner develops.

## Composer constraints

Dependencies are constrained to major versions only (`^8`). One deliberate
exception: `symfony/http-foundation` requires `^8.1`, because `RenderedResponse`
passes a header bag to the `Response` constructor — an 8.1 signature (8.0 only
accepts an array and would fail with a TypeError). Keep constraints major-only
unless the code genuinely uses a later minor's API; then raise the floor and
note why.

## Be respectful of other people's work

Our brainstorming and design notes often reference other CMSs, projects, and
implementations to explain where Garner's ideas come from and where it makes
different choices. Keep that comparison respectful:

- Credit prior work honestly; many of these projects are mature and well-loved.
- Frame differences as Garner's own trade-offs for its goals, not as flaws in others.
- Describe how another tool works factually; avoid loaded or dismissive language.
- Never disparage another project, vendor, or their community.

This applies to everything in the repository — code comments, commit messages, docs,
and any public-facing text.

## Don't name client projects

Real-world findings recorded in `docs/` (most often `brainstorming.md`) come from
actual sites built on Garner. Many are client work or under NDA, so never write the
exact site name, domain, or client/business name into the repository:

- Describe it by shape instead: "a client site," "a consumer site," "a client app,"
  adding whatever detail actually matters ("a multi-step form flow," "an
  image-heavy site") without naming it.
- Applies everywhere in the repo — docs, code comments, commit messages — the same
  scope as "Be respectful of other people's work" above.
- If the user explicitly names a project and says it's fine to reference (e.g. their
  own public site), that's an exception to make on request, not a default to assume.
