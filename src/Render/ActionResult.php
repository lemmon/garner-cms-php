<?php

declare(strict_types=1);

namespace Garner\Render;

/**
 * The outcome of a page action (+action.php) short of a full response. Two
 * constructors, each carrying a default a bare RenderedResponse gets wrong for
 * actions: failure() re-renders the page with its data exposed to the template
 * as `form` (422, the validation-failure status), and redirect() answers
 * Post/Redirect/Get with 303 See Other (RenderedResponse::redirect() defaults
 * to a method-preserving 308 — right for canonical redirects, wrong here).
 * Anything else — JSON, fragments, custom headers — is the escape hatch:
 * return a RenderedResponse from the action instead.
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
    ) {}

    /**
     * Re-render the page with $data available to the template as `form` —
     * typically the failed values and their validation errors.
     *
     * @param array<string, mixed> $data
     */
    public static function failure(array $data, int $status = 422): self
    {
        return new self($data, $status);
    }

    /**
     * Redirect after a successful action: 303 See Other by default, so the
     * client re-requests the target with GET (Post/Redirect/Get). Dispatch is
     * htmx-aware — an htmx POST is answered with 204 + HX-Redirect instead of
     * a 3xx, so htmx navigates the whole page rather than swapping the
     * redirect target into the form's hx-target.
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
}
