<?php

declare(strict_types=1);

use Garner\Core\Application;

return static fn(Application $app): array => [
    'ok' => true,
    'name' => 'site',
    'blueprint' => $app->blueprintLoader()->load('site'),
];
