<?php

declare(strict_types=1);

namespace Garner\Tests;

use Garner\Cli\PageListCommand;
use Garner\Cli\PageShowCommand;
use Garner\Core\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class PageListShowCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/garner-page-list-show-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    // page:list

    public function testListsWholeTreeExcludingDraftsByDefault(): void
    {
        $this->writeEntry('', ['title' => 'Home']);
        $this->writeEntry('about', ['title' => 'About']);
        $this->writeEntry('blog', ['title' => 'Blog']);
        $this->writeEntry('blog/hello', ['title' => 'Hello']);
        $this->writeEntry('blog/secret', ['title' => 'Secret', 'draft' => true]);

        $tester = $this->runCommand(new PageListCommand($this->app()), []);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('/', $display);
        self::assertStringContainsString('/about', $display);
        self::assertStringContainsString('/blog', $display);
        self::assertStringContainsString('/blog/hello', $display);
        self::assertStringNotContainsString('/blog/secret', $display);
        self::assertStringContainsString('4 page(s)', $display);
    }

    public function testListScopesToASubtree(): void
    {
        $this->writeEntry('', ['title' => 'Home']);
        $this->writeEntry('about', ['title' => 'About']);
        $this->writeEntry('blog', ['title' => 'Blog']);
        $this->writeEntry('blog/hello', ['title' => 'Hello']);

        $tester = $this->runCommand(new PageListCommand($this->app()), ['path' => 'blog']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('/blog', $display);
        self::assertStringContainsString('/blog/hello', $display);
        self::assertStringNotContainsString('/about', $display);
        self::assertStringContainsString('2 page(s)', $display);
    }

    public function testDraftsFlagIncludesOwnDraftsAndCascadeHiddenDescendants(): void
    {
        $this->writeEntry('', ['title' => 'Home']);
        $this->writeEntry('blog', ['title' => 'Blog', 'draft' => true]);
        $this->writeEntry('blog/hello', ['title' => 'Hello']);

        $tester = $this->runCommand(new PageListCommand($this->app()), ['--drafts' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('/blog', $display);
        self::assertStringContainsString('[draft]', $display);
        self::assertStringContainsString('[hidden]', $display);
        self::assertStringContainsString('3 page(s)', $display);
    }

    public function testJsonOutputShape(): void
    {
        $this->writeEntry('', ['title' => 'Home']);

        $tester = $this->runCommand(new PageListCommand($this->app()), ['--json' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded);
        self::assertSame('/', $decoded[0]['path']);
        self::assertSame('Home', $decoded[0]['title']);
        self::assertFalse($decoded[0]['draft']);
        self::assertFalse($decoded[0]['hidden']);
        self::assertArrayHasKey('id', $decoded[0]);
    }

    public function testEmptyResultUnderANonexistentPath(): void
    {
        $this->writeEntry('', ['title' => 'Home']);

        $tester = $this->runCommand(new PageListCommand($this->app()), ['path' => 'nowhere']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('No pages found under "/nowhere"', $tester->getDisplay());
    }

    public function testEscapesConsoleMarkupInTheEmptyResultMessage(): void
    {
        // Symfony's formatter parses and strips a recognized tag like
        // <info> even in undecorated output — an unescaped $root would
        // report "/" instead of the actual scope path.
        $this->writeEntry('', ['title' => 'Home']);

        $tester = $this->runCommand(new PageListCommand($this->app()), [
            'path' => '<info>nowhere</info>',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString(
            'No pages found under "/<info>nowhere</info>"',
            $tester->getDisplay(),
        );
    }

    public function testExcludesRouteEndpointsFromTheListing(): void
    {
        $this->writeEntry('', ['title' => 'Home']);
        $this->writeFile('routes/api/+controller.php', '<?php return fn() => null;');

        $tester = $this->runCommand(new PageListCommand($this->app()), []);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringNotContainsString('/api', $tester->getDisplay());
        self::assertStringContainsString('1 page(s)', $tester->getDisplay());
    }

    public function testUntitledPageShowsAPlaceholder(): void
    {
        $this->writeEntry('', []);

        $tester = $this->runCommand(new PageListCommand($this->app()), []);

        self::assertStringContainsString('(untitled)', $tester->getDisplay());
    }

    public function testListingSurvivesAMalformedSiblingContentFile(): void
    {
        // The listing reads path/id/title/draft/hidden straight off the
        // index — it must never hydrate a page's content files (which
        // would parse this and throw) just to print a row it never uses.
        $this->writeEntry('blog/hello', ['title' => 'Hello']);
        $this->writeFile('routes/blog/hello/broken.json', '{ not valid json');

        $tester = $this->runCommand(new PageListCommand($this->app()), []);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('/blog/hello', $tester->getDisplay());
    }

    // page:show

    public function testShowsAPageByRoute(): void
    {
        $this->writeEntry('blog/hello', ['title' => 'Hello', 'created' => '2026-08-01']);
        $this->writeFile('routes/blog/hello/main.md', 'Body copy');
        $this->writeFile('app/templates/default.twig', '{{ page.title }}');

        $tester = $this->runCommand(new PageShowCommand($this->app()), ['page' => 'blog/hello']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('/blog/hello', $display);
        self::assertStringContainsString('title:      Hello', $display);
        self::assertStringContainsString('status:     published', $display);
        self::assertStringContainsString('template:   default.twig', $display);
        self::assertStringContainsString('main.md -> content.main', $display);
    }

    public function testTextModeRendersFreeformPageMetadata(): void
    {
        $this->writeEntry('blog/hello', [
            'title' => 'Hello',
            'author_id' => 'auth1',
            'featured' => true,
        ]);
        $this->writeFile('app/templates/default.twig', '{{ page.title }}');

        $tester = $this->runCommand(new PageShowCommand($this->app()), ['page' => 'blog/hello']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('Metadata:', $display);
        self::assertStringContainsString('author_id: "auth1"', $display);
        self::assertStringContainsString('featured: true', $display);
    }

    public function testTextModeRendersFileSidecarMetadata(): void
    {
        $this->writeEntry('blog/hello', ['title' => 'Hello']);
        $this->writeFile('routes/blog/hello/team.jpg', 'binary-ish');
        $this->writeFile('routes/blog/hello/team.jpg.json', '{"alt":"Team"}');
        $this->writeFile('app/templates/default.twig', '{{ page.title }}');

        $tester = $this->runCommand(new PageShowCommand($this->app()), ['page' => 'blog/hello']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('team.jpg', $display);
        self::assertStringContainsString('alt: "Team"', $display);
    }

    public function testShowsAPageById(): void
    {
        $this->writeEntry('blog/hello', ['id' => 'stable-id', 'title' => 'Hello']);
        $this->writeFile('app/templates/default.twig', '{{ page.title }}');

        $tester = $this->runCommand(new PageShowCommand($this->app()), ['page' => 'stable-id']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('/blog/hello', $tester->getDisplay());
    }

    public function testShowsADraftPageByRouteWithoutAnyFlag(): void
    {
        $this->writeEntry('blog/hello', ['title' => 'Hello', 'draft' => true]);
        $this->writeFile('app/templates/default.twig', '{{ page.title }}');

        $tester = $this->runCommand(new PageShowCommand($this->app()), ['page' => 'blog/hello']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('status:     draft', $tester->getDisplay());
    }

    public function testReportsCascadeHiddenStatusDistinctlyFromOwnDraft(): void
    {
        $this->writeEntry('blog', ['title' => 'Blog', 'draft' => true]);
        $this->writeEntry('blog/hello', ['title' => 'Hello']);
        $this->writeFile('app/templates/default.twig', '{{ page.title }}');

        $tester = $this->runCommand(new PageShowCommand($this->app()), ['page' => 'blog/hello']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString(
            'hidden (cascaded from a draft ancestor)',
            $tester->getDisplay(),
        );
    }

    public function testADraftPageCannotBeAddressedByIdOnlyByRoute(): void
    {
        $this->writeEntry('blog/hello', ['id' => 'draft-id', 'title' => 'Hello', 'draft' => true]);
        $this->writeFile('app/templates/default.twig', '{{ page.title }}');

        $byId = $this->runCommand(new PageShowCommand($this->app()), ['page' => 'draft-id']);
        self::assertSame(Command::FAILURE, $byId->getStatusCode());

        $byRoute = $this->runCommand(new PageShowCommand($this->app()), ['page' => 'blog/hello']);
        self::assertSame(Command::SUCCESS, $byRoute->getStatusCode());
        self::assertStringContainsString('status:     draft', $byRoute->getDisplay());
    }

    public function testFailsForAnUnknownRouteOrId(): void
    {
        $tester = $this->runCommand(new PageShowCommand($this->app()), ['page' => 'nope']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('No page found for "nope"', $tester->getDisplay());
    }

    public function testEscapesConsoleMarkupInTheNotFoundMessage(): void
    {
        $tester = $this->runCommand(new PageShowCommand($this->app()), [
            'page' => '<info>nope</info>',
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString(
            'No page found for "<info>nope</info>"',
            $tester->getDisplay(),
        );
    }

    public function testResolvesNormallyWhenRouteAndIdPointToTheSamePage(): void
    {
        // A page's id defaults to its own directory name, so a route text
        // equal to that page's own id is the common case, not an ambiguity.
        $this->writeEntry('pricing', ['title' => 'Pricing']);
        $this->writeFile('app/templates/default.twig', '{{ page.title }}');

        $tester = $this->runCommand(new PageShowCommand($this->app()), ['page' => 'pricing']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('/pricing', $tester->getDisplay());
    }

    public function testFlagsAnAmbiguousArgumentInsteadOfGuessing(): void
    {
        // "pricing" is both a real route (owned by a different page) and
        // another page's explicit id — silently preferring the route would
        // misreport the id-holding page as if it didn't exist.
        $this->writeEntry('pricing', ['id' => 'route-owner-id', 'title' => 'Route Owner']);
        $this->writeEntry('products/pricing', ['id' => 'pricing', 'title' => 'Id Owner']);
        $this->writeFile('app/templates/default.twig', '{{ page.title }}');

        $tester = $this->runCommand(new PageShowCommand($this->app()), ['page' => 'pricing']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('is ambiguous', $tester->getDisplay());
        self::assertStringContainsString('--route or --id', $tester->getDisplay());
    }

    public function testRouteFlagForcesRouteInterpretation(): void
    {
        $this->writeEntry('pricing', ['id' => 'route-owner-id', 'title' => 'Route Owner']);
        $this->writeEntry('products/pricing', ['id' => 'pricing', 'title' => 'Id Owner']);
        $this->writeFile('app/templates/default.twig', '{{ page.title }}');

        $tester = $this->runCommand(new PageShowCommand($this->app()), [
            'page' => 'pricing',
            '--route' => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('title:      Route Owner', $tester->getDisplay());
    }

    public function testIdFlagForcesIdInterpretation(): void
    {
        $this->writeEntry('pricing', ['id' => 'route-owner-id', 'title' => 'Route Owner']);
        $this->writeEntry('products/pricing', ['id' => 'pricing', 'title' => 'Id Owner']);
        $this->writeFile('app/templates/default.twig', '{{ page.title }}');

        $tester = $this->runCommand(new PageShowCommand($this->app()), [
            'page' => 'pricing',
            '--id' => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('title:      Id Owner', $tester->getDisplay());
    }

    public function testRejectsBothRouteAndIdFlagsTogether(): void
    {
        $tester = $this->runCommand(new PageShowCommand($this->app()), [
            'page' => 'pricing',
            '--route' => true,
            '--id' => true,
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Use only one of --route, --id', $tester->getDisplay());
    }

    public function testFailsOnAnEmptyArgument(): void
    {
        $tester = $this->runCommand(new PageShowCommand($this->app()), ['page' => '  ']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Provide a route path or id', $tester->getDisplay());
    }

    public function testReportsARouteEndpointDistinctlyFromAPage(): void
    {
        $this->writeFile('routes/api/+controller.php', '<?php return fn() => null;');

        $tester = $this->runCommand(new PageShowCommand($this->app()), ['page' => 'api']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('route endpoint', $display);
        self::assertStringContainsString('+controller.php', $display);
        self::assertStringNotContainsString('template:', $display);
    }

    public function testEscapesConsoleMarkupInEndpointFields(): void
    {
        // A route segment can legitimately contain characters Symfony's
        // formatter treats as tag syntax — the filesystem places no such
        // restriction on directory names.
        $this->writeFile('routes/<info>weird/+controller.php', '<?php return fn() => null;');

        $tester = $this->runCommand(new PageShowCommand($this->app()), ['page' => '<info>weird']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('/<info>weird', $display);
        self::assertStringContainsString('<info>weird/+controller.php', $display);
    }

    public function testEscapesConsoleMarkupInAResolvedTemplateName(): void
    {
        $this->writeEntry('blog/hello', ['title' => 'Hello', 'template' => '<info>weird']);
        $this->writeFile('app/templates/<info>weird.twig', '{{ page.title }}');

        $tester = $this->runCommand(new PageShowCommand($this->app()), ['page' => 'blog/hello']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('template:   <info>weird.twig', $tester->getDisplay());
    }

    public function testJsonOutputIncludesContentFilesAndAssetSidecars(): void
    {
        $this->writeEntry('blog/hello', ['title' => 'Hello']);
        $this->writeFile('routes/blog/hello/main.md', 'Body copy');
        $this->writeFile('routes/blog/hello/team.jpg', 'binary-ish');
        $this->writeFile('routes/blog/hello/team.jpg.json', '{"alt":"Team"}');
        $this->writeFile('app/templates/default.twig', '{{ page.title }}');

        $tester = $this->runCommand(new PageShowCommand($this->app()), [
            'page' => 'blog/hello',
            '--json' => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $decoded = json_decode($tester->getDisplay(), true);

        self::assertSame(
            [
                ['file' => 'main.md', 'key' => 'main', 'accessor' => 'content.main'],
            ],
            $decoded['content'],
        );

        self::assertCount(1, $decoded['files']);
        self::assertSame('team.jpg', $decoded['files'][0]['name']);
        self::assertSame('image/jpeg', $decoded['files'][0]['mimeType']);
        self::assertSame(['alt' => 'Team'], $decoded['files'][0]['meta']);
    }

    public function testContentAccessorUsesBracketNotationForANonIdentifierKey(): void
    {
        // pathinfo() strips only the last extension, so "main.md.json" keys
        // as the literal "main.md" — "content.main.md" would parse in Twig
        // as nested attribute access (content['main']['md']), not this key.
        $this->writeEntry('blog/hello', ['title' => 'Hello']);
        $this->writeFile('routes/blog/hello/main.md.json', '{"a":1}');

        $tester = $this->runCommand(new PageShowCommand($this->app()), [
            'page' => 'blog/hello',
            '--json' => true,
        ]);

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertSame(
            [['file' => 'main.md.json', 'key' => 'main.md', 'accessor' => "content['main.md']"]],
            $decoded['content'],
        );
    }

    public function testAnEmptyMetaSerializesAsAJsonObjectNotAnArray(): void
    {
        $this->writeEntry('blog/hello', []);
        $this->writeFile('app/templates/default.twig', '{{ page.title }}');

        $tester = $this->runCommand(new PageShowCommand($this->app()), [
            'page' => 'blog/hello',
            '--json' => true,
        ]);

        self::assertStringContainsString('"meta": {}', $tester->getDisplay());
    }

    public function testAFileWithNoSidecarSerializesMetaAsAJsonObjectNotAnArray(): void
    {
        $this->writeEntry('blog/hello', ['title' => 'Hello']);
        $this->writeFile('routes/blog/hello/team.jpg', 'binary-ish');
        $this->writeFile('app/templates/default.twig', '{{ page.title }}');

        $tester = $this->runCommand(new PageShowCommand($this->app()), [
            'page' => 'blog/hello',
            '--json' => true,
        ]);

        self::assertStringContainsString('"meta": {}', $tester->getDisplay());
    }

    public function testReportsAStructuredFailureWhenMetadataCannotBeJsonEncoded(): void
    {
        // Symfony Yaml parses ".nan" as PHP's NAN float, and json_encode()
        // cannot represent NAN/INF — it returns false rather than throwing.
        // The command must not mistake that for an empty-but-successful
        // result the way an unchecked (string) cast would.
        $this->writeFile('routes/blog/hello/+page.yaml', "title: Hello\nrating: .nan\n");
        $this->writeFile('app/templates/default.twig', '{{ page.title }}');

        $tester = $this->runCommand(new PageShowCommand($this->app()), [
            'page' => 'blog/hello',
            '--json' => true,
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('could not be encoded as JSON', $tester->getDisplay());
    }

    public function testReportsACoLocatedTemplateFileRatherThanAName(): void
    {
        $this->writeEntry('blog/hello', ['title' => 'Hello']);
        $this->writeFile('routes/blog/hello/+template.twig', '{{ page.title }}');

        $tester = $this->runCommand(new PageShowCommand($this->app()), [
            'page' => 'blog/hello',
            '--json' => true,
        ]);

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertSame('routes/blog/hello/+template.twig', $decoded['template']['file']);
        self::assertNull($decoded['template']['name']);
    }

    public function testReportsAnUnresolvedTemplateAsAnErrorRatherThanCrashing(): void
    {
        // No app/templates/default.twig anywhere in this project.
        $this->writeEntry('blog/hello', ['title' => 'Hello']);

        $tester = $this->runCommand(new PageShowCommand($this->app()), [
            'page' => 'blog/hello',
            '--json' => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $decoded = json_decode($tester->getDisplay(), true);
        self::assertArrayHasKey('error', $decoded['template']);
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
