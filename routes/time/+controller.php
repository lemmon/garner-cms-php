<?php

declare(strict_types=1);

use Garner\Content\Page;
use Garner\Content\Site;
use Garner\Core\Application;
use Garner\Render\RenderedResponse;

// A co-located +controller.php that returns a RenderedResponse bypasses Twig
// entirely — here producing a JSON endpoint at /time. Return an array instead
// to merge data into the page template.
return static function (Page $page, Site $site, Application $app): RenderedResponse {
    return RenderedResponse::json([
        'page' => $page->id(),
        'now' => gmdate('c'),
    ]);
};
