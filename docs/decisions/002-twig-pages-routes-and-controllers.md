# 002 Twig Pages, Routes, and Controllers

Status: accepted

## Context

Garner needs a clear public-site model that stays template-first, but it also needs a simple way to return non-HTML responses and page-specific logic without inventing a second rendering mode.

## Decision

Use Twig as the page template engine.

Support two explicit bypass/enrichment surfaces:

- `site/routes.php` for direct custom responses
- `site/controllers/{template}.php` for page-level logic before Twig

Controller behavior:

- returning an associative array enriches Twig context
- returning `RenderedResponse` skips Twig

## Consequences

- public page rendering stays consistently Twig-based
- API-like or text responses do not require a separate headless profile
- controller logic remains explicit and close to the page template it supports
