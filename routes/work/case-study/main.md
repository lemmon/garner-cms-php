This page lives at `routes/work/case-study/` — but `routes/work/` has no
`+page.json` of its own, just a plain directory grouping related pages.

`page.parent` and `page.ancestors` skip straight past it to the nearest real
ancestor page (Home), the same way the `parent_path` column stored inside the
routing index does internally. A breadcrumb built from `page.ancestors` never
breaks on a grouping directory that was never meant to be a page — see
`+template.twig` beside this file.
