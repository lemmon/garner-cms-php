<?php

declare(strict_types=1);

namespace Garner\Tests;

use Garner\Core\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class TwigCacheTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/garner-twig-cache-test-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/app/templates', 0o777, true);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    public function testDebugModeStillEnablesTheCompiledCache(): void
    {
        $app = $this->app(['debug' => true]);

        $app->siteRenderer();

        self::assertDirectoryExists($this->root . '/runtime/cache/twig');
    }

    public function testProductionModeUsesTheSameCachePathAsDebug(): void
    {
        $app = $this->app(['debug' => false]);

        $app->siteRenderer();

        self::assertDirectoryExists($this->root . '/runtime/cache/twig');
    }

    public function testExplicitFalseDisablesTheCacheEvenInProduction(): void
    {
        $app = $this->app(['debug' => false, 'twig' => ['cache' => false]]);

        $app->siteRenderer();

        self::assertDirectoryDoesNotExist($this->root . '/runtime/cache/twig');
    }

    public function testExplicitEmptyStringDisablesTheCacheInDebug(): void
    {
        $app = $this->app(['debug' => true, 'twig' => ['cache' => '']]);

        $app->siteRenderer();

        self::assertDirectoryDoesNotExist($this->root . '/runtime/cache/twig');
    }

    public function testRenderingInDebugModeWritesACompiledTemplateToTheCache(): void
    {
        $this->writeFile('app/templates/home.twig', '<h1>{{ page.title }}</h1>');
        $this->writeFile('routes/+page.json', json_encode([
            'template' => 'home',
            'title' => 'Home',
        ], JSON_THROW_ON_ERROR));

        $app = $this->app(['debug' => true]);
        $app->publicSite()->respond('/');

        $compiled = glob($this->root . '/runtime/cache/twig/*/*');
        self::assertNotEmpty($compiled === false ? [] : $compiled);
    }

    /**
     * @param array<string, mixed> $appConfig
     */
    private function app(array $appConfig): Application
    {
        return new Application($this->root, $this->root, ['app' => $appConfig]);
    }

    private function writeFile(string $relativePath, string $contents): void
    {
        $path = $this->root . '/' . $relativePath;
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }

        file_put_contents($path, $contents);
    }
}
