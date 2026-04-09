<?php

declare(strict_types=1);

use Garner\Core\Application;
use PHPUnit\Framework\TestCase;

final class BootAppTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/garner-cms-boot-' . bin2hex(random_bytes(6));
        mkdir($this->projectRoot . '/config', 0o777, true);

        file_put_contents($this->projectRoot . '/config/app.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            return [
                'name' => 'Boot Test',
                'paths' => [
                    'site' => 'custom-site',
                ],
            ];
            PHP);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->projectRoot);
    }

    public function testBootFactoryBuildsApplicationWithCoreDefaultsAndProjectOverrides(): void
    {
        $factory = require dirname(__DIR__) . '/boot/app.php';
        $app = $factory($this->projectRoot, dirname(__DIR__));

        self::assertInstanceOf(Application::class, $app);
        self::assertSame(dirname(__DIR__) . '/backend', $app->backendPath());
        self::assertSame(dirname(__DIR__), $app->corePath());
        self::assertSame($this->projectRoot, $app->rootPath());
        self::assertSame($this->projectRoot . '/content', $app->projectPath('content'));
        self::assertSame($this->projectRoot . '/custom-site', $app->projectPath('site'));
        self::assertSame('Boot Test', $app->config('app.name'));
        self::assertSame('/studio', $app->config('app.routes.studio_prefix'));
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
