<?php

declare(strict_types=1);

namespace Garner\Render;

/**
 * The outcome of a page action (+action.php) short of a full response. Two
 * constructors, each carrying a default a bare RenderedResponse gets wrong for
 * actions: failure() re-renders the page with its data exposed to the template
 * as `form` (422, the validation-failure status), optionally answering htmx
 * with a named template fragment instead of the whole page, and redirect()
 * answers Post/Redirect/Get with 303 See Other (RenderedResponse::redirect()
 * defaults to a method-preserving 308 — right for canonical redirects, wrong
 * here). Anything else — JSON, custom fragments, custom headers — is the
 * escape hatch: return a RenderedResponse from the action instead.
 */
final class ActionResult
{
    /**
     * @param array<string, mixed> $data
     */
    private function __construct(
        private readonly array $data,
        private readonly int $status,
        private readonly ?string $location = null,
        private readonly ?string $fragment = null,
    ) {}

    /**
     * Re-render the page with $data available to the template as `form` —
     * typically the failed values and their validation errors.
     *
     * $fragment names a Twig block in the page template; when set, an htmx
     * POST (`HX-Request`) is answered with just that block rendered — same
     * context as the full re-render — instead of the whole page, so htmx can
     * swap the form in place. htmx does not swap 4xx responses by default;
     * the site opts in with the documented `htmx-config` meta tag. Non-htmx
     * requests always get the full page re-render. The block renders alone
     * ({% set %} outside it does not run), so it must be self-contained; a
     * template that cannot be should skip $fragment and let the form pluck
     * its piece from the full re-render with hx-select.
     *
     * @param array<string, mixed> $data
     */
    public static function failure(array $data, int $status = 422, ?string $fragment = null): self
    {
        return new self($data, $status, fragment: $fragment);
    }

    /**
     * Redirect after a successful action: 303 See Other by default, so the
     * client re-requests the target with GET (Post/Redirect/Get). Dispatch is
     * htmx-aware — an htmx POST is answered with 204 + HX-Redirect instead of
     * a 3xx, so htmx navigates the whole page rather than swapping the
     * redirect target into the form's hx-target. $status shapes the non-htmx
     * response only: an HX-Redirect navigation is always re-requested with
     * GET, so a custom 3xx has nothing to say there.
     */
    public static function redirect(string $location, int $status = 303): self
    {
        return new self([], $status, $location);
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->data;
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * Redirect target, or null for a failure result.
     */
    public function location(): ?string
    {
        return $this->location;
    }

    /**
     * Template block to answer an htmx POST failure with, or null when the
     * failure always re-renders the full page.
     */
    public function fragment(): ?string
    {
        return $this->fragment;
    }
}
