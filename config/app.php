<?php

declare(strict_types=1);

use Garner\Support\IdGeneratorType;

$debug = (static function (): bool {
    $configured = $_ENV['APP_DEBUG'] ?? null;

    if ($configured !== null) {
        return filter_var($configured, FILTER_VALIDATE_BOOL);
    }

    $host = (string) ($_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? '');
    $normalizedHost = strtolower(trim(explode(':', $host)[0]));

    return in_array($normalizedHost, ['localhost', '127.0.0.1', '::1'], true);
})();

return [
    'name' => 'Garner',
    'environment' => $_ENV['APP_ENV'] ?? ($debug ? 'development' : 'production'),
    'debug' => $debug,
    'url' => $_ENV['APP_URL'] ?? null,
    'paths' => [
        'app' => 'app',
        'routes' => 'routes',
        'runtime' => 'runtime',
        'storage' => 'storage',
    ],
    'markdown' => [
        'allow_unsafe_links' => false,
        'html_input' => 'strip',
    ],
    'ids' => [
        // Generator for scaffolded ids. Use an IdGeneratorType case for a built-in,
        // or an IdGenerator instance / callable / class-string for a custom one.
        // (Any unique string is a valid id regardless of generator.)
        'generator' => IdGeneratorType::Cuid2,
    ],
    'rendering' => [
        'default_template' => 'default',
        'engine' => 'twig',
    ],
    'index' => [
        // Derived SQLite route index freshness policy.
        // null inherits app.debug: "scan" for changes in development,
        // "locked" cached index in production. Override with "scan" or "locked".
        'mode' => null,
    ],
    'twig' => [
        'cache' => null,
        'debug' => null,
        'auto_reload' => null,
        'strict_variables' => false,
    ],
];
