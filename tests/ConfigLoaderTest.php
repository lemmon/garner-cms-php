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
        $items = scandir($this->configPath);

        if ($items !== false) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }

                unlink($this->configPath . '/' . $item);
            }
        }

        rmdir($this->configPath);
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

        self::assertSame([
            'app' => [
                'name' => 'Test Garner',
            ],
        ], $config);
    }
}
