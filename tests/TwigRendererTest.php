<?php

declare(strict_types=1);

use Garner\Site\TwigRenderer;
use PHPUnit\Framework\TestCase;
use Twig\Cache\FilesystemCache;

final class TwigRendererTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/garner-cms-twig-' . bin2hex(random_bytes(6));
        mkdir($this->projectRoot . '/site/templates', 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->projectRoot);
    }

    public function testTwigRendererCreatesConfiguredCacheDirectoryAndEnablesDebugExtension(): void
    {
        $cachePath = $this->projectRoot . '/runtime/cache/twig';
        $renderer = new TwigRenderer(
            templatesPath: $this->projectRoot . '/site/templates',
            options: [
                'cache' => $cachePath,
                'debug' => true,
            ],
        );

        $reflection = new ReflectionClass($renderer);
        $property = $reflection->getProperty('twig');
        $twig = $property->getValue($renderer);

        self::assertTrue(is_dir($cachePath));
        self::assertTrue($twig->isDebug());
        self::assertInstanceOf(FilesystemCache::class, $twig->getCache(false));
    }

    public function testTwigDumpFunctionIsAvailableEvenWhenDebugIsDisabled(): void
    {
        $renderer = new TwigRenderer(
            templatesPath: $this->projectRoot . '/site/templates',
            options: [
                'debug' => false,
            ],
        );

        $reflection = new ReflectionClass($renderer);
        $property = $reflection->getProperty('twig');
        $twig = $property->getValue($renderer);

        $output = $twig->createTemplate('{{ dump("hello") }}<p>ok</p>')->render();

        self::assertSame('<p>ok</p>', $output);
    }

    public function testTwigDumpFunctionUsesSymfonyHtmlDumperWhenDebugIsEnabled(): void
    {
        $renderer = new TwigRenderer(
            templatesPath: $this->projectRoot . '/site/templates',
            options: [
                'debug' => true,
            ],
        );

        $reflection = new ReflectionClass($renderer);
        $property = $reflection->getProperty('twig');
        $twig = $property->getValue($renderer);

        $output = $twig->createTemplate('{{ dump("hello") }}')->render();

        self::assertStringContainsString('sf-dump', $output);
        self::assertStringContainsString('hello', $output);
    }

    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path . '/' . $item;

            if (is_dir($itemPath)) {
                $this->deleteDirectory($itemPath);
                continue;
            }

            unlink($itemPath);
        }

        rmdir($path);
    }
}
