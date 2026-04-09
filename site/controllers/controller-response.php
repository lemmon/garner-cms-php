<?php

declare(strict_types=1);

use Garner\Core\Application;
use Garner\Site\Page;
use Garner\Site\Pages;
use Garner\Site\RenderedResponse;
use Garner\Site\Site;

return static fn(
    Page $page,
    Site $site,
    Pages $pages,
    Application $app,
): RenderedResponse => RenderedResponse::text(
    rtrim((string) $page->value('text', sprintf("Controller response for %s.\n", $page->title())))
        . "\n",
);
