<?php

declare(strict_types=1);

namespace Garner\Render;

use DateTimeInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response as HttpFoundationResponse;

/**
 * A finished HTTP response: body, status, and headers, ready to emit. A thin,
 * Garner-owned surface over an HttpFoundation response held internally — the
 * wrapped response is never exposed. Instances are immutable: the with*()
 * methods return modified copies, so a response can be built up fluently.
 */
final class RenderedResponse
{
    private HttpFoundationResponse $inner;

    public function __construct(
        string $body,
        int $status = 200,
        string $contentType = 'text/html; charset=utf-8',
        ?string $location = null,
    ) {
        $this->inner = new HttpFoundationResponse($body, $status, new VerbatimHeaderBag([
            'Content-Type' => $contentType,
        ]));

        // The verbatim bag keeps HttpFoundation from inventing cache policy —
        // Garner sends exactly the Cache-Control the developer set, or none.
        // The bag's own constructor still seeds an empty Cache-Control, so drop it.
        $this->inner->headers->remove('Cache-Control');

        if ($location !== null) {
            $this->inner->headers->set('Location', $location);
        }
    }

    public function __clone(): void
    {
        $this->inner = clone $this->inner;
    }

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

    /**
     * A copy of this response with the header set, replacing any existing value.
     * Header names are case-insensitive.
     */
    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->inner->headers->set($name, $value);

        return $clone;
    }

    /**
     * A copy of this response with a cookie added. The defaults are the safe
     * baseline: session lifetime, whole-site path, HttpOnly, SameSite=Lax.
     * $expires accepts a timestamp, a date string, or a DateTimeInterface;
     * 0 means "until the browser session ends".
     *
     * @param 'lax'|'strict'|'none'|null $sameSite null omits the SameSite attribute.
     */
    public function withCookie(
        string $name,
        string $value,
        DateTimeInterface|int|string $expires = 0,
        string $path = '/',
        ?string $domain = null,
        bool $secure = false,
        bool $httpOnly = true,
        ?string $sameSite = 'lax',
    ): self {
        $clone = clone $this;
        $clone
            ->inner
            ->headers
            ->setCookie(Cookie::create(
                $name,
                $value,
                $expires,
                $path,
                $domain,
                $secure,
                $httpOnly,
                sameSite: $sameSite,
            ));

        return $clone;
    }

    public function body(): string
    {
        return (string) $this->inner->getContent();
    }

    public function contentType(): string
    {
        return (string) $this->inner->headers->get('Content-Type');
    }

    public function status(): int
    {
        return $this->inner->getStatusCode();
    }

    /**
     * Redirect target, or null for a regular content response.
     */
    public function location(): ?string
    {
        return $this->inner->headers->get('Location');
    }

    /**
     * The named header's value, or null when the response doesn't carry it.
     * Header names are case-insensitive.
     */
    public function header(string $name): ?string
    {
        return $this->inner->headers->get($name);
    }

    /**
     * The response's Set-Cookie lines, in the order the cookies were added.
     *
     * @return list<string>
     */
    public function cookies(): array
    {
        return array_values(array_map(
            static fn(Cookie $cookie): string => (string) $cookie,
            $this->inner->headers->getCookies(),
        ));
    }

    /**
     * Emit the response: status line, headers, cookies, then the body.
     */
    public function send(): void
    {
        // Once output has been flushed the headers are gone: emit only the body.
        // HttpFoundation would still attempt the status line via header(), whose
        // "headers already sent" warning Garner's error handler escalates to an
        // ErrorException — fatal when it happens inside the exception handler.
        if (headers_sent()) {
            $this->inner->sendContent();

            return;
        }

        // HttpFoundation defaults the status line to HTTP/1.0; adopt the
        // protocol the SAPI negotiated so an HTTP/1.1 request is not answered
        // with a downgraded response.
        $protocol = $_SERVER['SERVER_PROTOCOL'] ?? null;

        if (is_string($protocol) && str_starts_with($protocol, 'HTTP/')) {
            $this->inner->setProtocolVersion(substr($protocol, 5));
        }

        $this->inner->send();
    }
}
