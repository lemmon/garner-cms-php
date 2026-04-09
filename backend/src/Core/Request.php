<?php

declare(strict_types=1);

namespace Garner\Core;

use JsonException;

final class Request
{
    /**
     * Detect if the current request arrived over HTTPS.
     * Checks common proxy headers and server variables.
     */
    public static function isHttps(): bool
    {
        $forwarded = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        if (strtolower((string) $forwarded) === 'https') {
            return true;
        }

        if (in_array((string) ($_SERVER['HTTPS'] ?? ''), ['on', '1'], true)) {
            return true;
        }

        return (string) ($_SERVER['REQUEST_SCHEME'] ?? '') === 'https';
    }

    public static function path(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }

    public static function publicBaseUrl(?string $override = null): ?string
    {
        if (is_string($override)) {
            $trimmed = trim($override);

            if ($trimmed !== '') {
                return rtrim($trimmed, '/');
            }
        }

        $host = self::publicHost();

        if ($host === null) {
            return null;
        }

        return (self::isHttps() ? 'https' : 'http') . '://' . $host;
    }

    public static function getInput(): string
    {
        $input = file_get_contents('php://input');

        if (($input === false || $input === '') && PHP_SAPI === 'cli') {
            $stdin = file_get_contents('php://stdin');

            return $stdin !== false ? $stdin : '';
        }

        return $input !== false ? $input : '';
    }

    private static function publicHost(): ?string
    {
        $forwardedHost = self::firstForwardedValue($_SERVER['HTTP_X_FORWARDED_HOST'] ?? null);

        if ($forwardedHost !== null) {
            return $forwardedHost;
        }

        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));

        if ($host !== '') {
            return $host;
        }

        $serverName = trim((string) ($_SERVER['SERVER_NAME'] ?? ''));

        if ($serverName === '') {
            return null;
        }

        $port = (string) ($_SERVER['SERVER_PORT'] ?? '');
        $defaultPort = self::isHttps() ? '443' : '80';

        if ($port === '' || $port === $defaultPort) {
            return $serverName;
        }

        return $serverName . ':' . $port;
    }

    private static function firstForwardedValue(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $first = trim(explode(',', $value)[0]);

        return $first !== '' ? $first : null;
    }

    /**
     * @return array<string, mixed>
     * @throws JsonException
     */
    public static function getPayload(int $maxBytes = 1_048_576): array
    {
        $input = self::getInput();

        if ($input === '') {
            return [];
        }

        if (strlen($input) > $maxBytes) {
            throw new JsonException('Payload too large');
        }

        $decoded = json_decode($input, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }
}
