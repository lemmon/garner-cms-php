<?php

declare(strict_types=1);

use Garner\Support\ConfigLoader;
use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function testConfigLoaderLoadsAppConfig(): void
    {
        $config = ConfigLoader::load(__DIR__ . '/../backend/config');

        self::assertSame('Garner CMS', $config['app']['name']);
        self::assertSame('/api', $config['app']['routes']['api_prefix']);
    }
}
