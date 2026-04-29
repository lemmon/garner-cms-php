<?php

declare(strict_types=1);

use Garner\Blueprint\BlueprintFieldNodes;
use Garner\Core\Application;
use Garner\Core\Request;
use Garner\Studio\PageUpdate;
use Garner\Support\Slug;
use Illuminate\Support\Str;
use Lemmon\Validator\Validator;

return static function (Application $app): array {
    $payload = Request::getPayload();
    $page = $app->pageRepository()->findOrFail($payload['id'] ?? null);
    $blueprint = $app->blueprintLoader()->loadPage((string) ($page['blueprint'] ?? 'default'));

    $updater = new PageUpdate(
        siteRepository: $app->siteRepository(),
        pageRepository: $app->pageRepository(),
        pathIndexer: $app->pathIndexer(),
        pathResolver: $app->pathResolver(),
    );

    $schema = [];

    foreach (BlueprintFieldNodes::validationSchema($blueprint) as $name => $fieldValidator) {
        if (!array_key_exists($name, $payload)) {
            continue;
        }

        $schema[$name] = $fieldValidator;
    }

    // Reserved page-level keys must win over colliding blueprint node names.
    if (array_key_exists('title', $payload)) {
        $schema['title'] = Validator::isString()
            ->pipe(Str::squish(...))
            ->notEmpty();
    }

    if (array_key_exists('slug', $payload) && $updater->slugEditableForPage($page)) {
        $schema['slug'] = Validator::isString()
            ->pipe(Slug::normalize(...))
            ->notEmpty('Value is required')
            ->satisfies(
                fn(string $value): bool => !$updater->slugExistsAmongSiblingsForPage($page, $value),
                'Slug must be unique among sibling pages',
            );
    }

    /** @var array<string, string> $validated */
    $validated = Validator::isAssociative($schema)->validate($payload);

    return $updater->update(page: $page, validated: $validated);
};
