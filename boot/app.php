<?php

declare(strict_types=1);

use Garner\Core\Application;
use Garner\Support\ConfigLoader;

return static function (string $projectPath, string $corePath): Application {
    return new Application(
        corePath: rtrim($corePath, '/'),
        projectRootPath: rtrim($projectPath, '/'),
        config: ConfigLoader::loadMany([
            rtrim($corePath, '/') . '/backend/config',
            rtrim($projectPath, '/') . '/config',
        ]),
    );
};
