<?php

declare(strict_types=1);

use Garner\Core\Application;
use Garner\Support\ConfigLoader;

return static function (string $projectPath, string $corePath): Application {
    $projectPath = rtrim($projectPath, '/');
    $corePath = rtrim($corePath, '/');

    return new Application(
        corePath: $corePath,
        projectRootPath: $projectPath,
        config: ConfigLoader::loadMany([
            $corePath . '/config',
            $projectPath . '/config',
        ]),
    );
};
