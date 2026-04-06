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

    public static function getInput(): string
    {
        $input = file_get_contents('php://input');

        if (($input === false || $input === '') && PHP_SAPI === 'cli') {
            $stdin = file_get_contents('php://stdin');

            return $stdin !== false ? $stdin : '';
        }

        return $input !== false ? $input : '';
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
