# 003 Configurable ID Generator

Status: accepted

## Context

Garner needs stable content identifiers, but not every project wants the same identifier format. `uuid_v7` is a good default, yet some projects may prefer shorter IDs or alternatives such as `cuid2`.

## Decision

Treat ID generation as an explicit abstraction.

Defaults:

- `uuid_v7` is the default generator

Requirements:

- projects must be able to swap the generator
- repositories, routing, and storage code must not hardcode one format

## Consequences

- Garner keeps a strong default without locking projects into one ID scheme
- future support for shorter IDs or `cuid2` does not require rethinking the whole content model
