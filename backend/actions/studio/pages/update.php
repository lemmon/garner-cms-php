<?php

declare(strict_types=1);

use Garner\Blueprint\BlueprintFieldNodes;
use Garner\Core\Application;
use Garner\Core\Request;
use Garner\Studio\PageUpdate;
use Garner\Support\Slug;
use Illuminate\Support\Str;
use Lemmon\Validator\ValidationException;
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

    if (array_key_exists('status', $payload) && $updater->statusEditableForPage($page)) {
        $schema['status'] = Validator::isString()->required()->in(['draft', 'unlisted', 'listed']);
    }

    $currentStatus = is_string($page['status'] ?? null) ? $page['status'] : null;
    $requestedStatus = is_string($payload['status'] ?? null) ? $payload['status'] : $currentStatus;

    if (
        $updater->statusEditableForPage($page)
        && $requestedStatus === 'listed'
        && (
            array_key_exists('status', $payload)
            || array_key_exists('position', $payload)
            || array_key_exists('sort', $payload)
        )
    ) {
        if (array_key_exists('position', $payload) || !array_key_exists('sort', $payload)) {
            $schema['position'] = Validator::isInt()->required()->min(1);
        }

        if (!array_key_exists('position', $payload) && array_key_exists('sort', $payload)) {
            $schema['sort'] = Validator::isInt()->required()->min(1);
        }
    }

    /** @var array<string, mixed> $validated */
    $validated = Validator::isAssociative($schema)->validate($payload);

    if ($validated === []) {
        throw new ValidationException([
            'payload' => ['At least one editable page field is required'],
        ]);
    }

    return $updater->update(page: $page, validated: $validated);
};
