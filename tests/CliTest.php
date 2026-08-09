<?php

declare(strict_types=1);

namespace Garner\Tests;

use Garner\Cli\CacheClearCommand;
use Garner\Cli\CreatePageCommand;
use Garner\Cli\PagePreviewCommand;
use Garner\Cli\ReindexCommand;
use Garner\Cli\ValidateCommand;
use Garner\Content\PublicSite;
use Garner\Core\Application;
use Garner\Core\Cache;
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

    public function testPagePreviewSetsAnExplicitPhrase(): void
    {
        $this->writeEntry('wip', ['created' => '2026-06-19', 'title' => 'WIP', 'draft' => true]);

        $tester = $this->runCommand(new PagePreviewCommand($this->app()), [
            'route' => 'wip',
            '--set' => 'letmein',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('letmein', $tester->getDisplay());

        $contents = (string) file_get_contents($this->root . '/routes/wip/+page.json');
        self::assertStringContainsString('"draft_preview": "letmein"', $contents);
        self::assertStringContainsString('"title": "WIP"', $contents);
    }

    public function testPagePreviewGeneratesARandomToken(): void
    {
        $this->writeEntry('wip', ['created' => '2026-06-19', 'draft' => true]);

        $tester = $this->runCommand(new PagePreviewCommand($this->app()), [
            'route' => 'wip',
            '--generate' => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $meta = json_decode(
            (string) file_get_contents($this->root . '/routes/wip/+page.json'),
            true,
        );
        self::assertIsArray($meta);
        self::assertIsString($meta['draft_preview'] ?? null);
        self::assertSame(24, strlen($meta['draft_preview']));
        self::assertStringContainsString($meta['draft_preview'], $tester->getDisplay());
    }

    public function testPagePreviewClearsTheField(): void
    {
        $this->writeEntry('wip', [
            'created' => '2026-06-19',
            'draft' => true,
            'draft_preview' => 'letmein',
        ]);

        $tester = $this->runCommand(new PagePreviewCommand($this->app()), [
            'route' => 'wip',
            '--clear' => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('removed', $tester->getDisplay());

        $meta = json_decode(
            (string) file_get_contents($this->root . '/routes/wip/+page.json'),
            true,
        );
        self::assertIsArray($meta);
        self::assertArrayNotHasKey('draft_preview', $meta);
    }

    public function testPagePreviewClearingAnAlreadyOffPageIsANoop(): void
    {
        $this->writeEntry('wip', ['created' => '2026-06-19', 'draft' => true]);

        $tester = $this->runCommand(new PagePreviewCommand($this->app()), [
            'route' => 'wip',
            '--clear' => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('already off', $tester->getDisplay());
    }

    public function testPagePreviewWithNoFlagReportsCurrentState(): void
    {
        $this->writeEntry('wip', [
            'created' => '2026-06-19',
            'draft' => true,
            'draft_preview' => 'letmein',
        ]);

        $tester = $this->runCommand(new PagePreviewCommand($this->app()), ['route' => 'wip']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('letmein', $tester->getDisplay());

        $unchanged = (string) file_get_contents($this->root . '/routes/wip/+page.json');
        self::assertStringContainsString('"draft_preview": "letmein"', $unchanged);
    }

    public function testPagePreviewFailsForAMissingPage(): void
    {
        $tester = $this->runCommand(new PagePreviewCommand($this->app()), ['route' => 'missing']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('No page exists', $tester->getDisplay());
    }

    public function testPagePreviewRejectsAnEmptyPhrase(): void
    {
        $this->writeEntry('wip', ['created' => '2026-06-19', 'draft' => true]);

        $tester = $this->runCommand(new PagePreviewCommand($this->app()), [
            'route' => 'wip',
            '--set' => '  ',
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());

        $unchanged = (string) file_get_contents($this->root . '/routes/wip/+page.json');
        self::assertStringNotContainsString('draft_preview', $unchanged);
    }

    public function testPagePreviewOpenRejectsAnAlreadyPublicPage(): void
    {
        $this->writeEntry('live', ['created' => '2026-06-19', 'title' => 'Live']);

        $command = new PagePreviewCommand(
            $this->app(),
            browserOpener: static fn(string $url): bool => true,
        );

        $tester = $this->runCommand($command, [
            'route' => 'live',
            '--open' => true,
            '--base-url' => 'http://localhost:8040',
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('already public', $tester->getDisplay());
    }

    public function testPagePreviewOpenFailsWithoutABaseUrl(): void
    {
        $this->writeEntry('wip', ['created' => '2026-06-19', 'draft' => true]);

        $tester = $this->runCommand(new PagePreviewCommand($this->app()), [
            'route' => 'wip',
            '--open' => true,
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Unable to determine the site URL', $tester->getDisplay());
    }

    public function testPagePreviewOpenIssuesAOneTimeEphemeralToken(): void
    {
        $this->writeEntry('wip', ['created' => '2026-06-19', 'draft' => true]);

        $app = $this->app();
        $command = new PagePreviewCommand(
            $app,
            browserOpener: static fn(string $url): bool => true,
        );

        $tester = $this->runCommand($command, [
            'route' => 'wip',
            '--open' => true,
            '--base-url' => 'http://localhost:8040',
            '--json' => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $payload = json_decode($tester->getDisplay(), true);
        self::assertIsArray($payload);
        self::assertSame('/wip', $payload['route']);
        self::assertTrue($payload['opened']);
        // No app.preview.open_ttl configured — falls back to 1800s (30 minutes):
        // the token is single-use and local-machine-only, so there's no reason
        // to keep the housekeeping window tight.
        self::assertSame(1800, $payload['ttlSeconds']);
        self::assertStringStartsWith('http://localhost:8040/wip?preview=', $payload['url']);

        $token = substr(
            $payload['url'],
            (int) strpos($payload['url'], 'preview=') + strlen('preview='),
        );
        self::assertSame(
            $token,
            $app->cache()->get(PublicSite::EPHEMERAL_PREVIEW_CACHE_PREFIX . '/wip'),
        );

        // Never written to the content file — this is the ephemeral path.
        $contents = (string) file_get_contents($this->root . '/routes/wip/+page.json');
        self::assertStringNotContainsString('draft_preview', $contents);
    }

    public function testPagePreviewOpenUsesTheConfiguredTtl(): void
    {
        $this->writeEntry('wip', ['created' => '2026-06-19', 'draft' => true]);

        $app = new Application($this->root, $this->root, [
            'app' => [
                'debug' => true,
                'name' => 'Test Site',
                'preview' => ['open_ttl' => 90],
            ],
        ]);
        $command = new PagePreviewCommand(
            $app,
            browserOpener: static fn(string $url): bool => true,
        );

        $tester = $this->runCommand($command, [
            'route' => 'wip',
            '--open' => true,
            '--base-url' => 'http://localhost:8040',
            '--json' => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $payload = json_decode($tester->getDisplay(), true);
        self::assertIsArray($payload);
        self::assertSame(90, $payload['ttlSeconds']);
    }

    public function testPagePreviewOpenReportsWhenTheBrowserCouldNotBeLaunched(): void
    {
        $this->writeEntry('wip', ['created' => '2026-06-19', 'draft' => true]);

        $command = new PagePreviewCommand(
            $this->app(),
            browserOpener: static fn(string $url): bool => false,
        );

        $tester = $this->runCommand($command, [
            'route' => 'wip',
            '--open' => true,
            '--base-url' => 'http://localhost:8040',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString(
            'Could not open a browser automatically',
            $tester->getDisplay(),
        );
    }

    public function testPagePreviewOpenWorksForAYamlBackedPage(): void
    {
        $this->writeFile('routes/wip/+page.yaml', "created: '2026-06-19'\ndraft: true\n");

        $command = new PagePreviewCommand(
            $this->app(),
            browserOpener: static fn(string $url): bool => true,
        );

        $tester = $this->runCommand($command, [
            'route' => 'wip',
            '--open' => true,
            '--base-url' => 'http://localhost:8040',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testPagePreviewStatusReportWorksForAYamlBackedPage(): void
    {
        $this->writeFile(
            'routes/wip/+page.yaml',
            "created: '2026-06-19'\ndraft: true\ndraft_preview: letmein\n",
        );

        $tester = $this->runCommand(new PagePreviewCommand($this->app()), ['route' => 'wip']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('letmein', $tester->getDisplay());
    }

    public function testPagePreviewSetStillRejectsAYamlBackedPage(): void
    {
        $this->writeFile('routes/wip/+page.yaml', "created: '2026-06-19'\ndraft: true\n");

        $tester = $this->runCommand(new PagePreviewCommand($this->app()), [
            'route' => 'wip',
            '--set' => 'letmein',
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString(
            'page:preview only edits +page.json',
            $tester->getDisplay(),
        );
    }

    public function testPagePreviewFailsRatherThanClaimingSuccessWhenTheEntryCannotBeWritten(): void
    {
        $this->writeEntry('wip', ['created' => '2026-06-19', 'draft' => true]);
        $path = $this->root . '/routes/wip/+page.json';
        // write() now goes via a sibling temp file + rename(), both governed
        // by the *directory's* write permission, not the target file's own
        // bits — so the directory, not the file, has to be unwritable here.
        $dir = dirname($path);
        chmod($dir, 0o555);

        if (is_writable($dir)) {
            chmod($dir, 0o755);
            self::markTestSkipped(
                'Cannot make the directory unwritable here (likely running as root).',
            );
        }

        $tester = $this->runCommand(new PagePreviewCommand($this->app()), [
            'route' => 'wip',
            '--set' => 'letmein',
        ]);

        chmod($dir, 0o755);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Unable to write', $tester->getDisplay());

        $contents = (string) file_get_contents($path);
        self::assertStringNotContainsString('draft_preview', $contents);
    }

    public function testPagePreviewSetsThePhraseOnTheHomeRoute(): void
    {
        $this->writeEntry('', ['created' => '2026-06-19', 'title' => 'Home', 'draft' => true]);

        $tester = $this->runCommand(new PagePreviewCommand($this->app()), [
            'route' => '/',
            '--set' => 'letmein',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('/ preview (set): letmein', $tester->getDisplay());

        $contents = (string) file_get_contents($this->root . '/routes/+page.json');
        self::assertStringContainsString('"draft_preview": "letmein"', $contents);
    }

    public function testPagePreviewOpensTheHomeRoute(): void
    {
        $this->writeEntry('', ['created' => '2026-06-19', 'title' => 'Home', 'draft' => true]);

        $command = new PagePreviewCommand(
            $this->app(),
            browserOpener: static fn(string $url): bool => true,
        );

        $tester = $this->runCommand($command, [
            'route' => '',
            '--open' => true,
            '--base-url' => 'http://localhost:8040',
            '--json' => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $payload = json_decode($tester->getDisplay(), true);
        self::assertIsArray($payload);
        self::assertSame('/', $payload['route']);
        self::assertStringStartsWith('http://localhost:8040/?preview=', $payload['url']);
    }

    public function testPagePreviewRejectsMultipleFlags(): void
    {
        $this->writeEntry('wip', ['created' => '2026-06-19', 'draft' => true]);

        $tester = $this->runCommand(new PagePreviewCommand($this->app()), [
            'route' => 'wip',
            '--set' => 'a',
            '--clear' => true,
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('only one of', $tester->getDisplay());
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

    public function testCacheClearRemovesApplicationValues(): void
    {
        $app = $this->app();
        $app->cache()->set('computed', ['value' => 42]);

        $tester = $this->runCommand(new CacheClearCommand($app), []);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Cleared application cache', $tester->getDisplay());
        self::assertNull($app->cache()->get('computed'));
    }

    public function testCacheClearSucceedsWithNothingToClear(): void
    {
        $tester = $this->runCommand(new CacheClearCommand($this->app()), []);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('already clear', $tester->getDisplay());
    }

    public function testCacheClearUsesTheActiveOverriddenCache(): void
    {
        $app = $this->app();
        $fake = new Cache($this->root . '/fake-cache.sqlite');
        $fake->set('computed', 'value');

        $tester = $app->withCache($fake, fn(): CommandTester => $this->runCommand(
            new CacheClearCommand($app),
            [],
        ));

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Cleared application cache', $tester->getDisplay());
        self::assertStringContainsString('fake-cache.sqlite', $tester->getDisplay());
        self::assertNull($fake->get('computed'));
    }

    public function testCacheClearReportsAlreadyClearForASchemalessFile(): void
    {
        $this->writeFile('runtime/cache/data.sqlite', '');

        $tester = $this->runCommand(new CacheClearCommand($this->app()), []);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Application cache already clear', $tester->getDisplay());
    }

    public function testCacheClearFailsRatherThanClaimingSuccessForACorruptFile(): void
    {
        $this->writeFile('runtime/cache/data.sqlite', 'not a sqlite database');

        $tester = $this->runCommand(new CacheClearCommand($this->app()), []);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString(
            'Unable to clear application cache',
            $tester->getDisplay(),
        );
        self::assertStringNotContainsString(
            'Application cache already clear',
            $tester->getDisplay(),
        );
    }

    public function testCacheClearStillRemovesTemplatesWhenApplicationCacheFails(): void
    {
        $this->writeFile('runtime/cache/target.sqlite', '');
        symlink(
            $this->root . '/runtime/cache/target.sqlite',
            $this->root . '/runtime/cache/data.sqlite',
        );
        $this->writeFile('runtime/cache/twig/ab/cdef.php', '<?php // compiled template');

        $tester = $this->runCommand(new CacheClearCommand($this->app()), []);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString(
            'Unable to clear application cache',
            $tester->getDisplay(),
        );
        self::assertStringContainsString('symlink', $tester->getDisplay());
        self::assertStringContainsString('Cleared template cache', $tester->getDisplay());
        self::assertDirectoryDoesNotExist($this->root . '/runtime/cache/twig');
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
