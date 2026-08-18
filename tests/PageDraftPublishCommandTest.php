<?php

declare(strict_types=1);

namespace Garner\Tests;

use Garner\Cli\PageDraftCommand;
use Garner\Cli\PageListCommand;
use Garner\Cli\PagePublishCommand;
use Garner\Core\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class PageDraftPublishCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/garner-page-draft-publish-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    // page:draft

    public function testMarksAPublishedPageAsDraft(): void
    {
        $this->writeEntry('blog/hello', ['id' => 'hello-id', 'title' => 'Hello']);

        $tester = $this->runCommand(new PageDraftCommand($this->app()), ['route' => 'blog/hello']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('/blog/hello is now a draft.', $tester->getDisplay());

        $meta = $this->readEntry('blog/hello');
        self::assertSame(true, $meta['draft']);
        self::assertSame('hello-id', $meta['id']);
        self::assertSame('Hello', $meta['title']);
    }

    public function testDraftingAnAlreadyDraftPageIsANoOpThatReportsUnchanged(): void
    {
        $this->writeEntry('blog/hello', ['title' => 'Hello', 'draft' => true]);

        $tester = $this->runCommand(new PageDraftCommand($this->app()), ['route' => 'blog/hello']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('/blog/hello is already a draft.', $tester->getDisplay());
    }

    public function testDraftsTheHomePage(): void
    {
        $this->writeEntry('', ['title' => 'Home']);

        $tester = $this->runCommand(new PageDraftCommand($this->app()), ['route' => '']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('/ is now a draft.', $tester->getDisplay());
        self::assertSame(true, $this->readEntry('')['draft']);
    }

    public function testDraftJsonOutputShape(): void
    {
        $this->writeEntry('blog/hello', ['title' => 'Hello']);

        $tester = $this->runCommand(new PageDraftCommand($this->app()), [
            'route' => 'blog/hello',
            '--json' => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $decoded = json_decode($tester->getDisplay(), true);
        self::assertSame(
            [
                'route' => '/blog/hello',
                'draft' => true,
                'changed' => true,
            ],
            $decoded,
        );
    }

    public function testDraftFailsForAnUnknownRoute(): void
    {
        $tester = $this->runCommand(new PageDraftCommand($this->app()), ['route' => 'missing']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('No page exists at "/missing".', $tester->getDisplay());
    }

    public function testDraftRejectsAnInvalidRoute(): void
    {
        $tester = $this->runCommand(new PageDraftCommand($this->app()), ['route' => '../etc']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Invalid route.', $tester->getDisplay());
    }

    public function testDraftRefusesAYamlEntry(): void
    {
        $this->writeFile('routes/blog/hello/+page.yaml', "title: Hello\n");

        $tester = $this->runCommand(new PageDraftCommand($this->app()), ['route' => 'blog/hello']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('page:draft only edits +page.json', $tester->getDisplay());
    }

    public function testDraftFailsWhenTheEntryDoesNotDecodeToAnObject(): void
    {
        $this->writeFile('routes/blog/hello/+page.json', '"just a string"');

        $tester = $this->runCommand(new PageDraftCommand($this->app()), ['route' => 'blog/hello']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('must decode to an object', $tester->getDisplay());
    }

    public function testDraftFailsWhenTheEntryIsAJsonArrayRatherThanAnObject(): void
    {
        $this->writeFile('routes/blog/hello/+page.json', '[1, 2, 3]');

        $tester = $this->runCommand(new PageDraftCommand($this->app()), ['route' => 'blog/hello']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('must decode to an object', $tester->getDisplay());
    }

    public function testDraftPreservesEmptyObjectAndArrayShapesAndFloatPrecisionInUnrelatedMetadata(): void
    {
        $this->writeFile('routes/blog/hello/+page.json', <<<'JSON'
            {
                "title": "Hello",
                "settings": {},
                "tags": [],
                "ratio": 1.0
            }
            JSON);

        $tester = $this->runCommand(new PageDraftCommand($this->app()), ['route' => 'blog/hello']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $raw = file_get_contents($this->root . '/routes/blog/hello/+page.json');
        self::assertIsString($raw);

        $decoded = json_decode($raw);
        self::assertInstanceOf(\stdClass::class, $decoded);
        self::assertInstanceOf(\stdClass::class, $decoded->settings);
        self::assertSame([], $decoded->tags);
        self::assertSame(1.0, $decoded->ratio);
        self::assertTrue($decoded->draft);
        self::assertStringContainsString('"ratio": 1.0', $raw);
    }

    public function testDraftPreservesOversizedIntegerPrecisionInUnrelatedMetadata(): void
    {
        $this->writeFile('routes/blog/hello/+page.json', <<<'JSON'
            {
                "title": "Hello",
                "big": 99999999999999999999
            }
            JSON);

        $tester = $this->runCommand(new PageDraftCommand($this->app()), ['route' => 'blog/hello']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $raw = file_get_contents($this->root . '/routes/blog/hello/+page.json');
        self::assertIsString($raw);
        self::assertStringContainsString('99999999999999999999', $raw);
    }

    public function testDraftResolvesTheRouteRegardlessOfTypedCase(): void
    {
        $this->writeEntry('Blog/Hello', ['title' => 'Hello']);

        $tester = $this->runCommand(new PageDraftCommand($this->app()), ['route' => 'blog/hello']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('/Blog/Hello is now a draft.', $tester->getDisplay());
    }

    // page:publish

    public function testPublishingRemovesTheDraftKeyEntirely(): void
    {
        $this->writeEntry('blog/hello', ['id' => 'hello-id', 'title' => 'Hello', 'draft' => true]);

        $tester = $this->runCommand(new PagePublishCommand($this->app()), [
            'route' => 'blog/hello',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('/blog/hello is now published.', $tester->getDisplay());

        $meta = $this->readEntry('blog/hello');
        self::assertArrayNotHasKey('draft', $meta);
        self::assertSame('hello-id', $meta['id']);
        self::assertSame('Hello', $meta['title']);
    }

    public function testPublishingAnAlreadyPublishedPageIsANoOpThatReportsUnchanged(): void
    {
        $this->writeEntry('blog/hello', ['title' => 'Hello']);

        $tester = $this->runCommand(new PagePublishCommand($this->app()), [
            'route' => 'blog/hello',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString(
            '/blog/hello is already published.',
            $tester->getDisplay(),
        );
    }

    public function testPublishingAPageWithDraftExplicitlyFalseIsANoOp(): void
    {
        $this->writeEntry('blog/hello', ['title' => 'Hello', 'draft' => false]);

        $tester = $this->runCommand(new PagePublishCommand($this->app()), [
            'route' => 'blog/hello',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString(
            '/blog/hello is already published.',
            $tester->getDisplay(),
        );
        // No-op: the explicit `false` is left untouched rather than rewritten.
        self::assertSame(false, $this->readEntry('blog/hello')['draft']);
    }

    public function testPublishingAPageWithOnlyDraftWritesAnEmptyObjectNotAnArray(): void
    {
        $this->writeFile('routes/blog/hello/+page.json', "{\"draft\": true}\n");

        $tester = $this->runCommand(new PagePublishCommand($this->app()), [
            'route' => 'blog/hello',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $raw = file_get_contents($this->root . '/routes/blog/hello/+page.json');
        self::assertIsString($raw);
        self::assertSame('{}', trim($raw));
    }

    public function testPublishPreservesEmptyObjectAndArrayShapesAndFloatPrecisionInUnrelatedMetadata(): void
    {
        $this->writeFile('routes/blog/hello/+page.json', <<<'JSON'
            {
                "title": "Hello",
                "draft": true,
                "settings": {},
                "tags": [],
                "ratio": 1.0
            }
            JSON);

        $tester = $this->runCommand(new PagePublishCommand($this->app()), [
            'route' => 'blog/hello',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $raw = file_get_contents($this->root . '/routes/blog/hello/+page.json');
        self::assertIsString($raw);

        $decoded = json_decode($raw);
        self::assertInstanceOf(\stdClass::class, $decoded);
        self::assertInstanceOf(\stdClass::class, $decoded->settings);
        self::assertSame([], $decoded->tags);
        self::assertSame(1.0, $decoded->ratio);
        self::assertObjectNotHasProperty('draft', $decoded);
        self::assertStringContainsString('"ratio": 1.0', $raw);
    }

    public function testPublishReliablyUpdatesTheIndexEvenWhenWrittenWithinTheSameSecondAsTheDraft(): void
    {
        $this->writeEntry('blog/hello', ['title' => 'Hello']);

        $this->runCommand(new PageDraftCommand($this->app()), ['route' => 'blog/hello']);
        $this->runCommand(new PagePublishCommand($this->app()), ['route' => 'blog/hello']);

        // A separate reader (a fresh ContentIndex, matching a new CLI process
        // or a page render) must see the published state. Both writes above
        // almost certainly landed within the same wall-clock second, which
        // ContentIndex::fingerprint()'s integer-mtime hashing alone can't
        // tell apart — only the explicit rebuild() in each command's
        // execute() guarantees this regardless of timing.
        $listTester = $this->runCommand(new PageListCommand($this->app()), [
            '--drafts' => true,
            '--json' => true,
        ]);
        $rows = json_decode($listTester->getDisplay(), true);
        self::assertIsArray($rows);

        $matches = array_values(array_filter(
            $rows,
            static fn(array $row): bool => $row['path'] === '/blog/hello',
        ));
        $hello = $matches[0] ?? null;

        self::assertIsArray($hello);
        self::assertFalse($hello['draft']);
        self::assertFalse($hello['hidden']);
    }

    public function testPublishesTheHomePage(): void
    {
        $this->writeEntry('', ['title' => 'Home', 'draft' => true]);

        $tester = $this->runCommand(new PagePublishCommand($this->app()), ['route' => '']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('/ is now published.', $tester->getDisplay());
        self::assertArrayNotHasKey('draft', $this->readEntry(''));
    }

    public function testPublishJsonOutputShape(): void
    {
        $this->writeEntry('blog/hello', ['title' => 'Hello', 'draft' => true]);

        $tester = $this->runCommand(new PagePublishCommand($this->app()), [
            'route' => 'blog/hello',
            '--json' => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $decoded = json_decode($tester->getDisplay(), true);
        self::assertSame(
            [
                'route' => '/blog/hello',
                'draft' => false,
                'changed' => true,
            ],
            $decoded,
        );
    }

    public function testPublishFailsForAnUnknownRoute(): void
    {
        $tester = $this->runCommand(new PagePublishCommand($this->app()), ['route' => 'missing']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('No page exists at "/missing".', $tester->getDisplay());
    }

    public function testPublishRejectsAnInvalidRoute(): void
    {
        $tester = $this->runCommand(new PagePublishCommand($this->app()), ['route' => '../etc']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Invalid route.', $tester->getDisplay());
    }

    public function testPublishRefusesAYamlEntry(): void
    {
        $this->writeFile('routes/blog/hello/+page.yaml', "title: Hello\ndraft: true\n");

        $tester = $this->runCommand(new PagePublishCommand($this->app()), [
            'route' => 'blog/hello',
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString(
            'page:publish only edits +page.json',
            $tester->getDisplay(),
        );
    }

    public function testPublishFailsWhenTheEntryDoesNotDecodeToAnObject(): void
    {
        $this->writeFile('routes/blog/hello/+page.json', '"just a string"');

        $tester = $this->runCommand(new PagePublishCommand($this->app()), [
            'route' => 'blog/hello',
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('must decode to an object', $tester->getDisplay());
    }

    // round trip

    public function testDraftThenPublishRoundTripsToTheOriginalPublishedShape(): void
    {
        $this->writeEntry('blog/hello', ['id' => 'hello-id', 'title' => 'Hello']);

        $this->runCommand(new PageDraftCommand($this->app()), ['route' => 'blog/hello']);
        $tester = $this->runCommand(new PagePublishCommand($this->app()), [
            'route' => 'blog/hello',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(['id' => 'hello-id', 'title' => 'Hello'], $this->readEntry('blog/hello'));
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

    /**
     * @return array<string, mixed>
     */
    private function readEntry(string $route): array
    {
        $directory = $route === '' ? 'routes' : 'routes/' . $route;
        $contents = file_get_contents($this->root . '/' . $directory . '/+page.json');
        self::assertIsString($contents);
        $decoded = json_decode($contents, true);
        self::assertIsArray($decoded);

        return $decoded;
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
