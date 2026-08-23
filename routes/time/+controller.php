<?php

declare(strict_types=1);

use Garner\Content\Page;
use Garner\Content\Site;
use Garner\Core\Application;

// A co-located +controller.php returning an array (not a RenderedResponse)
// merges that data into the page's template context — the controller->page
// pattern: PHP prepares data here, +template.twig renders it below. See
// routes/api/time/+controller.php for the JSON-endpoint shape (a
// controller-only route, no +page.json) this page used to take instead.
return static function (Page $page, Site $site, Application $app): array {
    return [
        'now' => gmdate('c'),
        // Same reasoning as routes/+controller.php's base_path: a
        // root-relative "/" would target the host's root instead of the
        // Garner home page once mounted under a subdirectory.
        'base_path' => $app->request()->basePath(),
    ];
};
