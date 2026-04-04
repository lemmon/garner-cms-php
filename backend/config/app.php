<?php

declare(strict_types=1);

return [
    'name' => 'Garner CMS',
    'environment' => $_ENV['APP_ENV'] ?? 'development',
    'debug' => filter_var($_ENV['APP_DEBUG'] ?? '1', FILTER_VALIDATE_BOOL),
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
    'rendering' => [
        'default_template' => 'default',
        'engine' => 'twig',
    ],
];
