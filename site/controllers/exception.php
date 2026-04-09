<?php

declare(strict_types=1);

use Garner\Core\Application;
use Garner\Site\Page;
use Garner\Site\Pages;
use Garner\Site\Site;

return static function (Page $page, Site $site, Pages $pages, Application $app): array {
    throw new RuntimeException('Deliberate exception from the exception page controller.');
};
