<?php

declare(strict_types=1);

$corePath = dirname(__DIR__);
$projectRoot =
    defined('GARNER_PROJECT_ROOT') && is_string(GARNER_PROJECT_ROOT) && GARNER_PROJECT_ROOT !== ''
        ? GARNER_PROJECT_ROOT
        : null;

// GARNER_PROJECT_ROOT (set by the consumer's public/index.php or the built-in
// server's router script) is derived from the request's document root or cwd,
// so it stays correct even when Garner is installed via a symlinked path
// repository for local development. $corePath cannot: PHP resolves __DIR__
// through the symlink to Garner's own source directory, so when Garner ships
// its own vendor/ (its dev install), that install would otherwise shadow the
// consumer's — the consumer's App\ classes and dependencies would go missing.
$autoloadPaths = [
    $projectRoot !== null ? $projectRoot . '/vendor/autoload.php' : null,
    $corePath . '/vendor/autoload.php',
    dirname($corePath, 2) . '/autoload.php',
];

$autoloadPath = null;

foreach ($autoloadPaths as $candidate) {
    if (!is_string($candidate) || !is_file($candidate)) {
        continue;
    }

    $autoloadPath = $candidate;
    break;
}

if ($autoloadPath === null) {
    throw new RuntimeException('Composer autoload not found. Run "composer install".');
}

require $autoloadPath;

$projectPath = $projectRoot ?? (static function (): string {
    $scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? '';

    if (!is_string($scriptFilename) || $scriptFilename === '') {
        $cwd = getcwd();

        return $cwd !== false ? $cwd : dirname(__DIR__);
    }

    $scriptDirectory = dirname($scriptFilename);

    return basename($scriptDirectory) === 'public' ? dirname($scriptDirectory) : $scriptDirectory;
})();

$appFactory = require $corePath . '/boot/app.php';
$app = $appFactory($projectPath, $corePath);
$app->run();
