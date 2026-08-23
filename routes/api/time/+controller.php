<?php

declare(strict_types=1);

use Garner\Content\Page;
use Garner\Content\Site;
use Garner\Core\Application;
use Garner\Render\RenderedResponse;

// Controller-only endpoint: no +page.json here, so this directory carries
// no metadata and never appears in the page tree (site.index, children,
// findById) — it only routes and dispatches. Like every controller in
// Garner it is GET/HEAD only; POST (and anything else) is a plain 405.
return static function (Page $page, Site $site, Application $app): RenderedResponse {
    return RenderedResponse::json([
        'now' => gmdate('c'),
    ]);
};
