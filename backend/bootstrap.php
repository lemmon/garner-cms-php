<?php

declare(strict_types=1);

$rootPath = dirname(__DIR__);
$vendorAutoload = $rootPath . '/vendor/autoload.php';

if (!is_file($vendorAutoload)) {
    throw new RuntimeException('Composer autoload not found. Run "composer install".');
}

require $vendorAutoload;

$appFactory = require $rootPath . '/boot/app.php';

return $appFactory($rootPath, $rootPath);
