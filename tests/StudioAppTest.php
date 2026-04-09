<?php

declare(strict_types=1);

use Garner\Studio\StudioApp;
use PHPUnit\Framework\TestCase;

final class StudioAppTest extends TestCase
{
    private string $buildPath;

    protected function setUp(): void
    {
        $this->buildPath =
            sys_get_temp_dir() . '/garner-cms-studio-build-' . bin2hex(random_bytes(6));

        mkdir($this->buildPath . '/_app/immutable/entry', 0o777, true);
        mkdir($this->buildPath . '/_app/immutable/assets', 0o777, true);

        file_put_contents($this->buildPath . '/index.html', '<!doctype html><title>Studio</title>');
        file_put_contents(
            $this->buildPath . '/_app/immutable/entry/start.js',
            'console.log("start");',
        );
        file_put_contents(
            $this->buildPath . '/_app/immutable/assets/app.css',
            'body{color:black;}',
        );
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->buildPath);
    }

    public function testStudioAppServesJavaScriptAndCssWithStableMimeTypes(): void
    {
        $app = new StudioApp($this->buildPath, '/studio');

        $script = $app->respond('/studio/_app/immutable/entry/start.js');
        $stylesheet = $app->respond('/studio/_app/immutable/assets/app.css');

        self::assertSame('application/javascript; charset=utf-8', $script->contentType());
        self::assertSame('text/css; charset=utf-8', $stylesheet->contentType());
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
