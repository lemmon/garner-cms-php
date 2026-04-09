<?php

declare(strict_types=1);

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$documentRoot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');

if (
    is_string($requestPath)
    && $requestPath !== '/'
    && $documentRoot !== ''
    && is_file($documentRoot . $requestPath)
) {
    return false;
}

if (!defined('GARNER_PROJECT_ROOT')) {
    $projectPath = $documentRoot !== ''
        ? (basename($documentRoot) === 'public' ? dirname($documentRoot) : $documentRoot)
        : (getcwd() ?: dirname(__DIR__));

    define('GARNER_PROJECT_ROOT', $projectPath);
}

require __DIR__ . '/web.php';
