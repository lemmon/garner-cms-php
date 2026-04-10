<?php

declare(strict_types=1);

use Garner\Core\Application;
use Garner\Core\Request;
use Garner\Studio\PageDetailQuery;

return static fn(Application $app): array => (new PageDetailQuery(
    siteRepository: $app->siteRepository(),
    pageRepository: $app->pageRepository(),
    pathResolver: $app->pathResolver(),
    blueprintLoader: $app->blueprintLoader(),
))->query(Request::getPayload());
