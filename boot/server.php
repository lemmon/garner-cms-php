<?php

declare(strict_types=1);

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$documentRoot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');

if (is_string($requestPath) && $requestPath !== '/' && $documentRoot !== '') {
    // rawurldecode() lets published media with spaces or non-ASCII names resolve to
    // its on-disk file, the way a real web server locates static files. Resolve the
    // request to a real path and require it to stay inside the document root, so the
    // decoding can't be abused for traversal (e.g. /%2e%2e/%2e%2e/config/app.php).
    $root = realpath($documentRoot);
    $file = realpath($documentRoot . rawurldecode($requestPath));

    if (
        $root !== false
        && $file !== false
        && is_file($file)
        && str_starts_with($file, $root . DIRECTORY_SEPARATOR)
    ) {
        return false;
    }
}

if (!defined('GARNER_PROJECT_ROOT')) {
    $cwd = getcwd();
    $projectPath = $cwd !== false ? $cwd : dirname(__DIR__);

    if ($documentRoot !== '') {
        $projectPath = basename($documentRoot) === 'public'
            ? dirname($documentRoot)
            : $documentRoot;
    }

    define('GARNER_PROJECT_ROOT', $projectPath);
}

require __DIR__ . '/web.php';
