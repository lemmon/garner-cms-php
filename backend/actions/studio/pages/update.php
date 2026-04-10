<?php

declare(strict_types=1);

use Garner\Core\Application;
use Garner\Core\Request;
use Garner\Studio\PageUpdate;
use Illuminate\Support\Str;
use Lemmon\Validator\Validator;

return static function (Application $app): array {
    $payload = Request::getPayload();
    $page = $app->pageRepository()->findOrFail($payload['id'] ?? null);

    $updater = new PageUpdate(
        siteRepository: $app->siteRepository(),
        pageRepository: $app->pageRepository(),
        pathIndexer: $app->pathIndexer(),
        pathResolver: $app->pathResolver(),
    );

    $schema = [
        'title' => Validator::isString()
            ->pipe(Str::squish(...))
            ->required()
            ->notEmpty(),
    ];

    if ($updater->slugEditableForPage($page)) {
        $schema['slug'] = Validator::isString()
            ->pipe(Str::slug(...))
            ->required()
            ->notEmpty('Value is required')
            ->satisfies(
                fn(string $value): bool => !$updater->slugExistsAmongSiblingsForPage($page, $value),
                'Slug must be unique among sibling pages',
            );
    }

    /** @var array{title: string, slug?: string} $validated */
    $validated = Validator::isAssociative($schema)->validate($payload);

    return $updater->update(
        page: $page,
        title: $validated['title'],
        slug: $validated['slug'] ?? null,
    );
};
