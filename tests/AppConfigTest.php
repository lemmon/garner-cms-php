<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AppConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['APP_DEBUG'], $_ENV['APP_ENV'], $_SERVER['SERVER_NAME'], $_SERVER['HTTP_HOST']);
    }

    public function testAppConfigDefaultsToDebugOnLocalhost(): void
    {
        unset($_ENV['APP_DEBUG'], $_ENV['APP_ENV'], $_SERVER['HTTP_HOST']);
        $_SERVER['SERVER_NAME'] = 'localhost';

        $config = require dirname(__DIR__) . '/backend/config/app.php';

        self::assertTrue($config['debug']);
        self::assertSame('development', $config['environment']);
    }

    public function testAppConfigDefaultsToProductionAwayFromLocalhost(): void
    {
        unset($_ENV['APP_DEBUG'], $_ENV['APP_ENV'], $_SERVER['SERVER_NAME']);
        $_SERVER['HTTP_HOST'] = 'example.com';

        $config = require dirname(__DIR__) . '/backend/config/app.php';

        self::assertFalse($config['debug']);
        self::assertSame('production', $config['environment']);
    }

    public function testAppConfigHonorsExplicitDebugOverride(): void
    {
        $_ENV['APP_DEBUG'] = 'true';
        $_ENV['APP_ENV'] = 'staging';
        $_SERVER['HTTP_HOST'] = 'example.com';

        $config = require dirname(__DIR__) . '/backend/config/app.php';

        self::assertTrue($config['debug']);
        self::assertSame('staging', $config['environment']);
    }
}
