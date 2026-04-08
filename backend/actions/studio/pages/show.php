<?php

declare(strict_types=1);

use Garner\Core\Application;
use Garner\Core\Request;
use Garner\Studio\PageDetailQuery;
use Lemmon\Validator\Validator;

return static function (Application $app): array {
    $schema = Validator::isAssociative([
        'id' => Validator::isString()->pipe(trim(...))->required()->notEmpty(),
    ]);

    $payload = $schema->validate(Request::getPayload());

    return (new PageDetailQuery(
        siteRepository: $app->siteRepository(),
        pageRepository: $app->pageRepository(),
        pathResolver: $app->pathResolver(),
        blueprintLoader: $app->blueprintLoader(),
    ))->query($payload);
};
