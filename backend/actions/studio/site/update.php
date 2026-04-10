<?php

declare(strict_types=1);

use Garner\Core\Application;
use Garner\Core\Request;
use Garner\Studio\SiteUpdate;
use Illuminate\Support\Str;
use Lemmon\Validator\Validator;

return static function (Application $app): array {
    $schema = Validator::isAssociative([
        'title' => Validator::isString()
            ->pipe(Str::squish(...))
            ->required()
            ->notEmpty(),
    ]);

    $payload = $schema->validate(Request::getPayload());

    return (new SiteUpdate(siteRepository: $app->siteRepository()))->update(
        title: $payload['title'],
    );
};
