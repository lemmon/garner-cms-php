<?php

declare(strict_types=1);

namespace Garner\Core;

use JsonException;
use Symfony\Component\HttpFoundation\File\UploadedFile as HttpFoundationUploadedFile;
use Symfony\Component\HttpFoundation\Request as HttpFoundationRequest;

/**
 * The current HTTP request. A thin, instance-based facade over HttpFoundation:
 * Garner code and userland see only this surface, never the wrapped request,
 * so the public API stays small and bare-accessor styled. One instance serves
 * the whole request; obtain it from Application::request().
 */
final class Request
{
    /**
     * @var array<string, UploadedFile|null>
     */
    private array $uploadedFiles = [];

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
     * $parameters become form fields for POST-style requests, and $body is the
     * raw request body (e.g. a JSON payload).
     *
     * @param array<string, mixed> $server
     * @param array<string, mixed> $parameters
     * @param array<string, string> $cookies
     * @param array<string, mixed> $files
     */
    public static function create(
        string $uri,
        string $method = 'GET',
        array $server = [],
        array $parameters = [],
        array $cookies = [],
        array $files = [],
        ?string $body = null,
    ): self {
        return new self(HttpFoundationRequest::create(
            uri: $uri,
            method: $method,
            parameters: $parameters,
            cookies: $cookies,
            files: $files,
            server: $server,
            content: $body,
        ));
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
     * The named request header's value, or the default when the request
     * doesn't carry it. Header names are case-insensitive.
     */
    public function header(string $name, ?string $default = null): ?string
    {
        return $this->inner->headers->get($name, $default);
    }

    /**
     * The named request cookie's value, or the default when absent.
     */
    public function cookie(string $name, ?string $default = null): ?string
    {
        $value = $this->inner->cookies->get($name, $default);

        return $value === null ? null : (string) $value;
    }

    /**
     * The raw request body, unparsed.
     */
    public function body(): string
    {
        return $this->inner->getContent();
    }

    /**
     * Form fields from a submitted body (urlencoded or multipart), as PHP
     * parsed them — nested field names like `items[]` arrive as arrays.
     *
     * @return array<string, mixed>
     */
    public function form(): array
    {
        return $this->inner->request->all();
    }

    /**
     * The request body decoded as JSON. An empty body yields an empty array,
     * as does a payload that is valid JSON but not an object or list.
     *
     * @return array<array-key, mixed>
     * @throws JsonException on a malformed body
     */
    public function json(): array
    {
        $body = $this->body();

        if ($body === '') {
            return [];
        }

        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The named uploaded file from a multipart submission, or null when the
     * field is absent (or holds multiple files — take those from form-level
     * handling when the need arises). Repeat calls return the same instance,
     * so the captured metadata stays valid after moveTo() consumes the upload.
     */
    public function file(string $name): ?UploadedFile
    {
        if (array_key_exists($name, $this->uploadedFiles)) {
            return $this->uploadedFiles[$name];
        }

        $file = $this->inner->files->get($name);

        return $this->uploadedFiles[$name] = $file instanceof HttpFoundationUploadedFile
            ? new UploadedFile($file)
            : null;
    }

    /**
     * Whether the request was made by htmx (it sends `HX-Request: true` on
     * every request it issues).
     */
    public function isHtmx(): bool
    {
        return $this->inner->headers->get('HX-Request') === 'true';
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
