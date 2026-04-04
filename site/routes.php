<?php

declare(strict_types=1);

use Garner\Core\Application;
use Garner\Site\RenderedResponse;

return [
    '/example.txt' =>
        static fn(Application $app): RenderedResponse => RenderedResponse::text(sprintf(
            "%s example plain-text route.\n",
            (string) $app->config('app.name', 'Garner CMS'),
        )),
    '/example.json' => static fn(Application $app): RenderedResponse => RenderedResponse::json([
        'example' => true,
        'name' => (string) $app->config('app.name', 'Garner CMS'),
        'route' => '/example.json',
    ]),
];
