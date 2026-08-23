<?php

declare(strict_types=1);

use Garner\Content\Page;
use Garner\Content\Site;
use Garner\Core\Application;

// Supplies the home page's inline API-demo script (see the "Live API
// examples" section in app/templates/home.twig) with the current request's
// base path: "" at web root, "/blog" behind a subdirectory install.
// Hardcoding root-relative fetch('/api/...') calls in that script would
// target the wrong path once Garner is mounted under a base path.
return static function (Page $page, Site $site, Application $app): array {
    return [
        'base_path' => $app->request()->basePath(),
    ];
};
