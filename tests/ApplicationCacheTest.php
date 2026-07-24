<?php

declare(strict_types=1);

namespace Garner\Tests;

use Garner\Core\Application;
use Garner\Core\Cache;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ApplicationCacheTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/garner-app-cache-test-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    public function testDefaultCacheLivesUnderProjectRuntime(): void
    {
        $app = $this->app([]);

        $app->cache()->set('key', 'value');

        self::assertFileExists($this->root . '/runtime/cache/data.sqlite');
    }

    public function testCacheReturnsTheSameInstance(): void
    {
        $app = $this->app([]);

        self::assertSame($app->cache(), $app->cache());
    }

    public function testObtainingTheCacheCreatesNoFile(): void
    {
        $app = $this->app([]);

        $app->cache()->get('anything');

        self::assertFileDoesNotExist($this->root . '/runtime/cache/data.sqlite');
    }

    public function testConfiguredRelativePathResolvesAgainstProjectRoot(): void
    {
        $app = $this->app(['cache' => ['path' => 'var/cache.sqlite']]);

        $app->cache()->set('key', 'value');

        self::assertFileExists($this->root . '/var/cache.sqlite');
    }

    public function testConfiguredAbsolutePathIsUsedAsIs(): void
    {
        $path = $this->root . '/elsewhere/cache.sqlite';
        $app = $this->app(['cache' => ['path' => $path]]);

        $app->cache()->set('key', 'value');

        self::assertFileExists($path);
    }

    public function testWithCacheInjectsAFakeForTheDurationOfTheCallback(): void
    {
        $app = $this->app([]);
        $fake = new Cache($this->root . '/fake.sqlite');
        $fake->set('injected', true);

        $result = $app->withCache($fake, static fn(): mixed => $app->cache()->get('injected'));

        self::assertTrue($result);
        self::assertNull($app->cache()->get('injected'));
        self::assertFileDoesNotExist($this->root . '/runtime/cache/data.sqlite');
    }

    /**
     * @param array<string, mixed> $appConfig
     */
    private function app(array $appConfig): Application
    {
        return new Application($this->root, $this->root, ['app' => $appConfig]);
    }
}
