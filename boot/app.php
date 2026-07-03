<?php

declare(strict_types=1);

use Garner\Core\Application;
use Garner\Support\ConfigLoader;
use Symfony\Component\Dotenv\Dotenv;

return static function (string $projectPath, string $corePath): Application {
    $projectPath = rtrim($projectPath, '/');
    $corePath = rtrim($corePath, '/');

    // Populate $_ENV from the project's .env before config loads (config files read
    // $_ENV). Symfony's cascade applies — .env, .env.local, .env.{APP_ENV},
    // .env.{APP_ENV}.local — and real environment variables always win over file
    // values. No .env means no-op; the file is optional.
    if (is_file($projectPath . '/.env')) {
        new Dotenv()->loadEnv($projectPath . '/.env', defaultEnv: 'development');
    }

    return new Application(
        corePath: $corePath,
        projectRootPath: $projectPath,
        config: ConfigLoader::loadMany([
            $corePath . '/config',
            $projectPath . '/config',
        ]),
    );
};
