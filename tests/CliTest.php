<?php

declare(strict_types=1);

namespace Garner\Tests;

use Garner\Cli\CacheClearCommand;
use Garner\Cli\CreatePageCommand;
use Garner\Cli\ReindexCommand;
use Garner\Cli\ValidateCommand;
use Garner\Core\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CliTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/garner-cli-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testValidatePassesOnCleanTree(): void
    {
        $this->writeEntry('', ['created' => '2026-06-19', 'title' => 'Home']);
        $this->writeEntry('about', ['created' => '2026-06-19', 'title' => 'About']);

        $tester = $this->runCommand(new ValidateCommand($this->app()), []);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('No problems found', $tester->getDisplay());
    }

    public function testValidateReportsDuplicateIds(): void
    {
        $this->writeEntry('', ['created' => '2026-06-19']);
        $this->writeEntry('a', ['id' => 'dup', 'created' => '2026-06-19']);
        $this->writeEntry('b', ['id' => 'dup', 'created' => '2026-06-19']);

        $tester = $this->runCommand(new ValidateCommand($this->app()), []);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Duplicate page id "dup"', $tester->getDisplay());
    }

    public function testValidateReportsBadJsonAsJson(): void
    {
        $this->writeEntry('', ['created' => '2026-06-19']);
        $this->writeFile('routes/broken/+page.json', '{ not valid json ');

        $tester = $this->runCommand(new ValidateCommand($this->app()), ['--json' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('"ok": false', $tester->getDisplay());
        self::assertStringContainsString('broken/+page.json', $tester->getDisplay());
    }

    public function testPageCreateScaffoldsEntry(): void
    {
        $tester = $this->runCommand(new CreatePageCommand($this->app()), [
            'route' => 'blog/hello',
            '--title' => 'Hello',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $path = $this->root . '/routes/blog/hello/+page.json';
        self::assertFileExists($path);

        $contents = (string) file_get_contents($path);
        self::assertStringContainsString('"id":', $contents);
        self::assertStringContainsString('"created":', $contents);
        self::assertStringContainsString('"title": "Hello"', $contents);
    }

    public function testPageCreateRefusesToClobber(): void
    {
        $this->writeEntry('blog/hello', ['created' => '2026-06-19', 'title' => 'Existing']);

        $tester = $this->runCommand(new CreatePageCommand($this->app()), ['route' => 'blog/hello']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString(
            'Existing',
            (string) file_get_contents($this->root . '/routes/blog/hello/+page.json'),
        );
    }

    public function testPageCreateDryRunWritesNothing(): void
    {
        $tester = $this->runCommand(new CreatePageCommand($this->app()), [
            'route' => 'blog/hello',
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertFileDoesNotExist($this->root . '/routes/blog/hello/+page.json');
    }

    public function testPageCreateRejectsUnsafeRoute(): void
    {
        $tester = $this->runCommand(new CreatePageCommand($this->app()), ['route' => '../escape']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertFileDoesNotExist($this->root . '/escape/+page.json');
    }

    public function testReindexBuildsIndex(): void
    {
        $this->writeEntry('', ['created' => '2026-06-19']);

        $tester = $this->runCommand(new ReindexCommand($this->app()), []);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertFileExists($this->root . '/runtime/index.sqlite');
    }

    public function testCacheClearRemovesCompiledTemplates(): void
    {
        $this->writeFile('runtime/cache/twig/ab/cdef.php', '<?php // compiled template');

        $tester = $this->runCommand(new CacheClearCommand($this->app()), []);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Cleared template cache', $tester->getDisplay());
        self::assertDirectoryDoesNotExist($this->root . '/runtime/cache/twig');
    }

    public function testCacheClearSucceedsWithNothingToClear(): void
    {
        $tester = $this->runCommand(new CacheClearCommand($this->app()), []);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('already clear', $tester->getDisplay());
    }

    /**
     * @param array<string, mixed> $input
     */
    private function runCommand(Command $command, array $input): CommandTester
    {
        $tester = new CommandTester($command);
        $tester->execute($input);

        return $tester;
    }

    private function app(): Application
    {
        return new Application($this->root, $this->root, [
            'app' => ['debug' => true, 'name' => 'Test Site'],
        ]);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function writeEntry(string $route, array $meta): void
    {
        $directory = $route === '' ? 'routes' : 'routes/' . $route;
        $json = json_encode($meta, JSON_PRETTY_PRINT);
        $this->writeFile($directory . '/+page.json', $json !== false ? $json : '{}');
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
