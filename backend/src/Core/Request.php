<?php

declare(strict_types=1);

namespace Garner\Core;

final class Request
{
    public static function path(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }
}
