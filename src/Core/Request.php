<?php

declare(strict_types=1);

namespace Garner\Core;

use Symfony\Component\HttpFoundation\Request as HttpFoundationRequest;

/**
 * The current HTTP request. A thin, instance-based facade over HttpFoundation:
 * Garner code and userland see only this surface, never the wrapped request,
 * so the public API stays small and bare-accessor styled. One instance serves
 * the whole request; obtain it from Application::request().
 */
final class Request
{
    private function __construct(
        private readonly HttpFoundationRequest $inner,
    ) {}

    public static function fromGlobals(): self
    {
        return new self(HttpFoundationRequest::createFromGlobals());
    }

    /**
     * Build a request from a URI, primarily for tests and custom boot paths.
     * Scheme, host, port, path, and query are taken from the URI; `$server`
     * entries (e.g. HTTP_X_FORWARDED_PROTO) override the derived defaults.
     *
     * @param array<string, mixed> $server
     */
    public static function create(string $uri, string $method = 'GET', array $server = []): self
    {
        return new self(HttpFoundationRequest::create(uri: $uri, method: $method, server: $server));
    }

    /**
     * The HTTP method, uppercased.
     */
    public function method(): string
    {
        return $this->inner->getMethod();
    }

    /**
     * The route path of the request: no query string, and with any front-controller
     * base path stripped, so a subdirectory install still yields "/about".
     */
    public function path(): string
    {
        return $this->inner->getPathInfo();
    }

    /**
     * The base path stripped from path(), without a trailing slash: "" at web
     * root, "/blog" for a subdirectory install, "/blog/index.php" when the front
     * controller is addressed directly. Framework-built redirects re-attach it
     * so they stay inside the app.
     */
    public function basePath(): string
    {
        return $this->inner->getBaseUrl();
    }

    /**
     * The raw query string without the leading "?", verbatim as sent — canonical
     * redirects re-attach it, so it must not be re-encoded or re-ordered. Empty
     * when the request has none.
     */
    public function query(): string
    {
        $uri = $this->inner->getRequestUri();
        $pos = strpos($uri, '?');

        return $pos === false ? '' : substr($uri, $pos + 1);
    }

    /**
     * Detect if the request arrived over HTTPS, honoring the X-Forwarded-Proto
     * header set by reverse proxies alongside the direct server variables.
     */
    public function isHttps(): bool
    {
        $forwarded = (string) $this->inner->headers->get('X-Forwarded-Proto', '');

        if (strtolower($forwarded) === 'https') {
            return true;
        }

        return (
            $this->inner->isSecure()
            || $this->inner->server->getString('REQUEST_SCHEME') === 'https'
        );
    }

    /**
     * The request's base URL (scheme://host[:port]) without a trailing slash.
     * Falls back to http://localhost when no Host header is available (e.g. CLI),
     * where the origin should be pinned via the app.url config.
     */
    public function baseUrl(): string
    {
        $host = trim(
            (string) (
                $this->inner->headers->get('Host') ?? $this->inner->server->getString('SERVER_NAME')
            ),
        );

        if ($host === '') {
            $host = 'localhost';
        }

        return ($this->isHttps() ? 'https' : 'http') . '://' . $host;
    }
}
