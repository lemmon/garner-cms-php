<?php

declare(strict_types=1);

use Garner\Site\Favicon;
use PHPUnit\Framework\TestCase;

final class FaviconTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/garner-cms-favicon-' . bin2hex(random_bytes(6));
        mkdir($this->projectRoot . '/site', 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->projectRoot);
    }

    public function testFallbackFaviconUsesBlueIcoPayload(): void
    {
        $favicon = new Favicon($this->projectRoot . '/site');
        $content = $favicon->content();

        self::assertSame('image/x-icon', $favicon->contentType());
        self::assertSame('000001000100', bin2hex(substr($content, 0, 6)));
        self::assertGreaterThan(1000, strlen($content));
    }

    public function testCustomSiteFaviconOverridesFallback(): void
    {
        file_put_contents($this->projectRoot . '/site/favicon.ico', 'custom-favicon');

        $favicon = new Favicon($this->projectRoot . '/site');

        self::assertSame('custom-favicon', $favicon->content());
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
