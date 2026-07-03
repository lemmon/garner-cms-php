<?php

declare(strict_types=1);

namespace Garner\Render;

use RuntimeException;

final class RenderedResponse
{
    public function __construct(
        private readonly string $body,
        private readonly int $status = 200,
        private readonly string $contentType = 'text/html; charset=utf-8',
        private readonly ?string $location = null,
    ) {}

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, 'text/html; charset=utf-8');
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function json(array $payload, int $status = 200): self
    {
        $body = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (!is_string($body)) {
            throw new RuntimeException('Unable to encode JSON response');
        }

        return new self($body, $status, 'application/json; charset=utf-8');
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($body, $status, 'text/plain; charset=utf-8');
    }

    /**
     * A permanent redirect to a URL or path. 308 preserves the request method
     * across the redirect (301 lets clients replay a POST as a GET).
     */
    public static function redirect(string $location, int $status = 308): self
    {
        return new self('', $status, 'text/html; charset=utf-8', $location);
    }

    public function body(): string
    {
        return $this->body;
    }

    public function contentType(): string
    {
        return $this->contentType;
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * Redirect target, or null for a regular content response.
     */
    public function location(): ?string
    {
        return $this->location;
    }
}
