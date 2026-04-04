<?php

declare(strict_types=1);

use Garner\Core\Application;

return static function (Application $app): array {
    $app->pathIndexer()->rebuild();

    $site = $app->siteRepository()->read();
    $pages = $app->pageRepository()->all()->map(
        static function (array $page) use ($app, $site): array {
            $id = is_string($page['id'] ?? null) ? $page['id'] : '';
            $fields = is_array($page['fields'] ?? null) ? $page['fields'] : [];
            $title = is_string($fields['title'] ?? null) && $fields['title'] !== ''
                ? $fields['title']
                : $id;

            return [
                'id' => $id,
                'parent_id' => is_string($page['parent_id'] ?? null) ? $page['parent_id'] : null,
                'slug' => is_string($page['slug'] ?? null) ? $page['slug'] : null,
                'status' => is_string($page['status'] ?? null) ? $page['status'] : 'draft',
                'sort' => is_int($page['sort'] ?? null) ? $page['sort'] : null,
                'template' => is_string($page['template'] ?? null) ? $page['template'] : 'default',
                'title' => $title,
                'path' => $app->pathResolver()->pathForId($id),
                'is_home' => is_string($site['home_page_id'] ?? null) && $site['home_page_id'] === $id,
            ];
        },
    )->values();

    return [
        'ok' => true,
        'site' => [
            'title' => is_string($site['title'] ?? null) ? $site['title'] : 'Garner CMS',
            'home_page_id' => is_string($site['home_page_id'] ?? null) ? $site['home_page_id'] : null,
        ],
        'stats' => [
            'page_count' => $pages->count(),
            'listed_count' => $pages->where('status', 'listed')->count(),
            'unlisted_count' => $pages->where('status', 'unlisted')->count(),
            'draft_count' => $pages->where('status', 'draft')->count(),
        ],
        'pages' => $pages->all(),
    ];
};
