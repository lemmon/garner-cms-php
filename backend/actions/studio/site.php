<?php

declare(strict_types=1);

use Garner\Core\Application;
use Garner\Core\Request;

return static function (Application $app): array {
    $site = $app->siteRepository()->read();

    return [
        'ok' => true,
        'site' => [
            'id' => is_string($site['id'] ?? null) ? $site['id'] : 'site',
            'title' => is_string($site['title'] ?? null) ? $site['title'] : 'Garner CMS',
            'url' => Request::publicBaseUrl(
                is_string($app->config('app.url')) ? $app->config('app.url') : null,
            ),
            'error_page_id' => is_string($site['error_page_id'] ?? null)
                ? $site['error_page_id']
                : null,
            'home_page_id' => is_string($site['home_page_id'] ?? null)
                ? $site['home_page_id']
                : null,
        ],
    ];
};
