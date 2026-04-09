<?php

declare(strict_types=1);

use Garner\Core\Application;
use Garner\Site\Page;
use Garner\Site\Pages;
use Garner\Site\Site;

return static function (Page $page, Site $site, Pages $pages, Application $app): array {
    if (!(bool) $app->config('app.debug', false)) {
        return [];
    }

    $payload = [
        'debug' => true,
        'page' => $page->data(),
        'site' => $site->data(),
        'listed_pages' => $pages
            ->listed()
            ->map(static fn(Page $item): array => $item->data())
            ->values()
            ->all(),
    ];

    if (function_exists('dump')) {
        // @mago-expect lint:no-debug-symbols
        dump($payload);
    }

    return [];
};
