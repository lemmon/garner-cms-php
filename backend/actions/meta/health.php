<?php

declare(strict_types=1);

use Garner\Core\Application;

return static fn(Application $app): array => [
    'ok' => true,
    'name' => (string) $app->config('app.name', 'Garner CMS'),
    'environment' => (string) $app->config('app.environment', 'development'),
    'render_engine' => (string) $app->config('app.rendering.engine', 'twig'),
    'api_prefix' => (string) $app->config('app.routes.api_prefix', '/api'),
    'studio_prefix' => (string) $app->config('app.routes.studio_prefix', '/studio'),
    'php_version' => PHP_VERSION,
    'timestamp_utc' => gmdate(DATE_ATOM),
];
