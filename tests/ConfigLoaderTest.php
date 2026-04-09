<?php

declare(strict_types=1);

use Garner\Support\ConfigLoader;
use PHPUnit\Framework\TestCase;

final class ConfigLoaderTest extends TestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        $this->configPath = sys_get_temp_dir() . '/garner-cms-config-' . bin2hex(random_bytes(6));
        mkdir($this->configPath, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->configPath);
    }

    public function testConfigLoaderLoadsOnlyArrayReturningPhpFiles(): void
    {
        file_put_contents($this->configPath . '/app.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            return [
                'name' => 'Test Garner',
            ];
            PHP);

        file_put_contents($this->configPath . '/notes.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            return 'ignore me';
            PHP);

        $config = ConfigLoader::load($this->configPath);

        self::assertSame(
            [
                'app' => [
                    'name' => 'Test Garner',
                ],
            ],
            $config,
        );
    }

    public function testConfigLoaderCanMergeMultipleDirectories(): void
    {
        $basePath = $this->configPath . '/base';
        $overridePath = $this->configPath . '/override';

        mkdir($basePath, 0o777, true);
        mkdir($overridePath, 0o777, true);

        file_put_contents($basePath . '/app.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            return [
                'paths' => [
                    'content' => 'content',
                    'site' => 'site',
                ],
                'routes' => [
                    'studio_prefix' => '/studio',
                ],
            ];
            PHP);

        file_put_contents($overridePath . '/app.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            return [
                'paths' => [
                    'site' => 'custom-site',
                ],
                'routes' => [
                    'api_prefix' => '/api',
                ],
            ];
            PHP);

        $config = ConfigLoader::loadMany([$basePath, $overridePath]);

        self::assertSame(
            [
                'app' => [
                    'paths' => [
                        'content' => 'content',
                        'site' => 'custom-site',
                    ],
                    'routes' => [
                        'studio_prefix' => '/studio',
                        'api_prefix' => '/api',
                    ],
                ],
            ],
            $config,
        );
    }

    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);

        if ($items !== false) {
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
        }

        rmdir($path);
    }
}
