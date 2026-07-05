<?php

declare(strict_types=1);

namespace Garner\Tests;

use Garner\Core\Application;
use Garner\Core\Store;
use PHPUnit\Framework\TestCase;

final class ApplicationStoreTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/garner-app-store-test-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testDefaultStoreLivesUnderProjectStorage(): void
    {
        $app = $this->app([]);

        $app->store()->set('key', 'value');

        self::assertFileExists($this->root . '/storage/store.sqlite');
    }

    public function testStoreReturnsTheSameInstance(): void
    {
        $app = $this->app([]);

        self::assertSame($app->store(), $app->store());
    }

    public function testObtainingTheStoreCreatesNoFile(): void
    {
        $app = $this->app([]);

        $app->store()->get('anything');

        self::assertFileDoesNotExist($this->root . '/storage/store.sqlite');
    }

    public function testConfiguredRelativePathResolvesAgainstProjectRoot(): void
    {
        $app = $this->app(['store' => ['path' => 'var/data.sqlite']]);

        $app->store()->set('key', 'value');

        self::assertFileExists($this->root . '/var/data.sqlite');
    }

    public function testConfiguredAbsolutePathIsUsedAsIs(): void
    {
        $path = $this->root . '/elsewhere/data.sqlite';
        $app = $this->app(['store' => ['path' => $path]]);

        $app->store()->set('key', 'value');

        self::assertFileExists($path);
    }

    public function testWithStoreInjectsAFakeForTheDurationOfTheCallback(): void
    {
        $app = $this->app([]);
        $fake = new Store($this->root . '/fake.sqlite');
        $fake->set('injected', true);

        $result = $app->withStore($fake, static fn(): mixed => $app->store()->get('injected'));

        self::assertTrue($result);
        self::assertNull($app->store()->get('injected'));
        self::assertFileDoesNotExist($this->root . '/storage/store.sqlite');
    }

    /**
     * @param array<string, mixed> $appConfig
     */
    private function app(array $appConfig): Application
    {
        return new Application($this->root, $this->root, ['app' => $appConfig]);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory);

        foreach ($items === false ? [] : $items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($directory);
    }
}
