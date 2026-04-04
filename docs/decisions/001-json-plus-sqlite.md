# 001 JSON Plus SQLite

Status: accepted

## Context

Garner wants file-native content for Git, portability, and straightforward backups, but it should not use the filesystem as the runtime query engine.

## Decision

Use:

- JSON files as the canonical content store
- SQLite as a derived local index

Canonical content lives in `content/`. The derived index lives in `runtime/index.sqlite`.

## Consequences

- content remains inspectable and Git-friendly
- routing and tree traversal are fast
- the index can be rebuilt from files
- page identity is not tied to physical directory nesting
