# 003 Configurable ID Generator

Status: accepted

## Context

Garner needs stable content identifiers that are independent from page slugs. UUID v4 is the current core default, but some projects may prefer shorter IDs or alternatives such as `cuid2`.

## Decision

Treat ID generation as an explicit abstraction.

Defaults:

- UUID v4 is the default generator
- generator resolution lives at the application boundary
- core features receive an `IdGenerator` dependency rather than calling UUID-specific helpers

Implementation note:

- the built-in UUID v4 generator currently uses PHP's native `random_bytes()` and RFC 4122 version/variant bit masking
- this is intentionally a small core default, not a commitment to maintaining broader UUID tooling in Garner
- introduce a dedicated third-party ID package only when Garner needs multiple built-in formats, parsing/validation helpers, or interoperability behavior that would otherwise duplicate library code

Requirements:

- projects must be able to swap the generator
- repositories, routing, and storage code must not hardcode one format

## Consequences

- Garner keeps a strong default without locking projects into one ID scheme
- future support for shorter IDs or `cuid2` does not require rethinking the whole content model
