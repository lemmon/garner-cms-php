# 004 Core CLI

Status: accepted

## Context

Operational commands such as reindexing are part of Garner's core storage model. Requiring a separate CLI package would split the product surface unnecessarily.

## Decision

Ship the CLI with Garner core.

Target behavior:

- installed projects get `bin/garner`
- core maintenance commands come from the main Garner package
- plugins and projects can extend one CLI surface

## Consequences

- operators do not need a companion package for fundamental workflows
- CLI behavior stays aligned with the same services used by HTTP and Studio flows
