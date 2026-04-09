<?php

declare(strict_types=1);

use Garner\Core\Application;
use Garner\Site\Page;
use Garner\Site\Pages;
use Garner\Site\Site;

return static fn(Page $page, Site $site, Pages $pages, Application $app): array => [
    'controller_message' => sprintf(
        '%s says: %s is controller-backed.',
        (string) $app->config('app.name', 'Garner CMS'),
        $page->title(),
    ),
];
