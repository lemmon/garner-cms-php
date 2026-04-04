<?php

declare(strict_types=1);

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if (is_string($requestPath) && $requestPath !== '/' && is_file(__DIR__ . $requestPath)) {
    return false;
}

require __DIR__ . '/index.php';
