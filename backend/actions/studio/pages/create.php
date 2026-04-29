<?php

declare(strict_types=1);

use Garner\Core\Application;
use Garner\Core\Request;
use Garner\Studio\PageCreate;
use Garner\Support\Slug;
use Illuminate\Support\Str;
use Lemmon\Validator\ValidationException;
use Lemmon\Validator\Validator;

return static function (Application $app): array {
    $creator = new PageCreate(
        siteRepository: $app->siteRepository(),
        pageRepository: $app->pageRepository(),
        pathIndexer: $app->pathIndexer(),
        pathResolver: $app->pathResolver(),
        idGenerator: $app->idGenerator(),
    );

    /** @var array{source: string, title: string, slug: string} $validated */
    $validated = Validator::isAssociative([
        'source' => Validator::isString()
            ->pipe(trim(...))
            ->required()
            ->notEmpty(),
        'title' => Validator::isString()
            ->pipe(Str::squish(...))
            ->required()
            ->notEmpty(),
        'slug' => Validator::isString()
            ->pipe(Slug::normalize(...))
            ->required()
            ->notEmpty('Value is required'),
    ])->validate(Request::getPayload());

    if ($creator->slugExistsAmongSiblingsForSource($validated['source'], $validated['slug'])) {
        throw new ValidationException([
            'slug' => ['Slug must be unique among sibling pages'],
        ]);
    }

    return $creator->create($validated);
};
