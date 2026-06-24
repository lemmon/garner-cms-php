# Welcome to Garner

Garner is an agent-first, flat-file CMS. This page is just a directory with a
`+page.json` entry and a `main.md` content file beside it.

- **Filesystem routing** — this directory is the root of the `routes/` tree, so it answers `/`.
- **Freeform content** — drop in any `.md`, `.json`, or `.yaml` file and it
  becomes a named value (`main.md` → `content.main`) for the template.
- **Only `+page.json` has rules** — just `created` is required; `template` falls
  back to the default and `id` is inherited from the directory name when omitted.
