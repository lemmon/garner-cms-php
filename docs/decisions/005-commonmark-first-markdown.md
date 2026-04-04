# 005: CommonMark-First Markdown

Garner should use `league/commonmark` for Markdown parsing.

Rules:

- Markdown fields stay plain strings in JSON
- Twig renders Markdown through a Garner-owned service and filter
- Garner should not introduce a KirbyTags-style proprietary inline language in v1
- simple links and images should prefer normal Markdown plus attributes
- richer CMS objects should use structured fields or purpose-built extensions later

Reasoning:

- CommonMark has strong tooling and an established parser in PHP
- it keeps prose in a standard format
- it avoids turning content into a private CMS mini-language
- it keeps the parser choice behind Garner code instead of leaking vendor APIs into templates
