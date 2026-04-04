<?php

declare(strict_types=1);

namespace Garner\Core;

final class Response
{
    public static function content(string $body, string $contentType, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: ' . $contentType);

        echo $body;
        exit();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit();
    }

    public static function html(string $html, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');

        echo $html;
        exit();
    }
}
