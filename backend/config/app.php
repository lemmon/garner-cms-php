<?php

declare(strict_types=1);

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
    'name' => 'Garner CMS',
    'environment' => $_ENV['APP_ENV'] ?? ($debug ? 'development' : 'production'),
    'debug' => $debug,
    'url' => $_ENV['APP_URL'] ?? null,
    'default_action' => 'meta/health',
    'paths' => [
        'content' => 'content',
        'runtime' => 'runtime',
        'site' => 'site',
        'storage' => 'storage',
    ],
    'markdown' => [
        'allow_unsafe_links' => false,
        'html_input' => 'strip',
    ],
    'routes' => [
        'api_prefix' => '/api',
        'studio_prefix' => '/studio',
    ],
    'studio' => [
        'build_path' => 'frontend/build',
    ],
    'ids' => [
        'generator' => 'uuid_v4',
    ],
    'rendering' => [
        'default_template' => 'default',
        'engine' => 'twig',
    ],
    'twig' => [
        'cache' => null,
        'debug' => null,
        'auto_reload' => null,
        'strict_variables' => false,
    ],
];
