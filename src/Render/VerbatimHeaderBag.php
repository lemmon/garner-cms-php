<?php

declare(strict_types=1);

namespace Garner\Render;

use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * A response header bag without HttpFoundation's Cache-Control heuristics. The
 * stock bag invents cache policy: it defaults every response to "no-cache,
 * private", appends ", private" to an explicit "max-age=60", and re-adds a
 * Cache-Control header whenever ETag, Last-Modified, or Expires is set.
 * Garner's contract is verbatim headers — a response carries exactly the
 * Cache-Control string the developer set, byte for byte, or none at all. The
 * stock set() stores the raw value first and only then overwrites it when the
 * computed value is non-empty, so an always-empty computation disables every
 * rewrite: nothing is auto-added, and explicit values are never parsed and
 * re-serialized (which would reorder directives and normalize quoting).
 */
final class VerbatimHeaderBag extends ResponseHeaderBag
{
    protected function computeCacheControlValue(): string
    {
        return '';
    }
}
