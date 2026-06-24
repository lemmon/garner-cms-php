<?php

declare(strict_types=1);

$corePath = dirname(__DIR__);
$autoloadPaths = [
    $corePath . '/vendor/autoload.php',
    dirname(dirname($corePath)) . '/autoload.php',
];

$autoloadPath = null;

foreach ($autoloadPaths as $candidate) {
    if (!is_file($candidate)) {
        continue;
    }

    $autoloadPath = $candidate;
    break;
}

if ($autoloadPath === null) {
    throw new RuntimeException('Composer autoload not found. Run "composer install".');
}

require $autoloadPath;

$projectPath = defined('GARNER_PROJECT_ROOT')
    ? GARNER_PROJECT_ROOT
    : (static function (): string {
        $scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? '';

        if (!is_string($scriptFilename) || $scriptFilename === '') {
            $cwd = getcwd();

            return $cwd !== false ? $cwd : dirname(__DIR__);
        }

        $scriptDirectory = dirname($scriptFilename);

        return basename($scriptDirectory) === 'public'
            ? dirname($scriptDirectory)
            : $scriptDirectory;
    })();

$appFactory = require $corePath . '/boot/app.php';
$app = $appFactory($projectPath, $corePath);
$app->run();
