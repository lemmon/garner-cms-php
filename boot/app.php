<?php

declare(strict_types=1);

use Garner\Core\Application;
use Garner\Support\ConfigLoader;
use Garner\Support\Env;
use Symfony\Component\Dotenv\Dotenv;

return static function (string $projectPath, string $corePath): Application {
    $projectPath = rtrim($projectPath, '/');
    $corePath = rtrim($corePath, '/');

    // Populate $_ENV from the project's .env before config loads (config files read
    // $_ENV). Symfony's cascade applies — .env, .env.local, .env.{APP_ENV},
    // .env.{APP_ENV}.local — and real environment variables always win over file
    // values. No .env means no-op; the file is optional.
    //
    // When nothing defines APP_ENV, Dotenv still writes *some* value into $_ENV
    // (its own "defaultEnv" fallback) so it knows which cascade files to look for —
    // it doesn't leave APP_ENV genuinely unset. That value flows straight into
    // config/app.php's `environment`, so it must already match Garner's own
    // host-based default (production unless localhost) — a hardcoded 'development'
    // here would silently override that default the moment a .env file exists at
    // all, independent of its content or the deploy host.
    if (is_file($projectPath . '/.env')) {
        new Dotenv()->loadEnv(
            $projectPath . '/.env',
            defaultEnv: Env::isLocalhost() ? 'development' : 'production',
        );
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
