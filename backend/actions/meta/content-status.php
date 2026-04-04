<?php

declare(strict_types=1);

use Garner\Core\Application;

return static function (Application $app): array {
    $index = $app->pathIndexer()->rebuild();

    return [
        'ok' => true,
        'site' => $app->siteRepository()->read(),
        'page_count' => $app->pageRepository()->all()->count(),
        'indexed_entry_count' => $index['entry_count'],
        'indexed_path_count' => $index['path_count'],
        'index_path' => $index['index_path'],
        'resolved_paths' => [
            '/' => $app->pathResolver()->resolve('/'),
            '/about' => $app->pathResolver()->resolve('/about'),
            '/markdown' => $app->pathResolver()->resolve('/markdown'),
        ],
    ];
};
