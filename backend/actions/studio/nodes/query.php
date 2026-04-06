<?php

declare(strict_types=1);

use Garner\Core\Application;
use Garner\Core\Request;
use Garner\Studio\NodeQuery;
use Garner\Studio\PageListItemSerializer;
use Garner\Studio\PageListQuery;
use Lemmon\Validator\Validator;

return static function (Application $app): array {
    $schema = Validator::isAssociative([
        'type' => Validator::isString()->required()->in(['page_list', 'file_list']),
        'source' => Validator::isString()
            ->pipe(trim(...))
            ->required()
            ->notEmpty(),
        'query' => Validator::isString()
            ->pipe(trim(...))
            ->nullifyEmpty(),
    ]);

    $payload = $schema->validate(Request::getPayload());

    return (new NodeQuery(new PageListQuery(
        siteRepository: $app->siteRepository(),
        pageRepository: $app->pageRepository(),
        pathResolver: $app->pathResolver(),
        serializer: new PageListItemSerializer($app->pageRepository()),
    )))->query($payload);
};
