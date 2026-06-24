<?php

declare(strict_types=1);

namespace Garner\Core;

final class Response
{
    public static function content(string $body, string $contentType, int $status = 200): never
    {
        self::applyHeaders($contentType, $status);

        echo $body;
        exit();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function json(array $payload, int $status = 200): never
    {
        self::applyHeaders('application/json; charset=utf-8', $status);

        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit();
    }

    public static function html(string $html, int $status = 200): never
    {
        self::applyHeaders('text/html; charset=utf-8', $status);

        echo $html;
        exit();
    }

    private static function applyHeaders(string $contentType, int $status): void
    {
        if (headers_sent()) {
            return;
        }

        http_response_code($status);
        header('Content-Type: ' . $contentType);
    }
}
