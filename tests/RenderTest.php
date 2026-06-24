<?php

declare(strict_types=1);

namespace Garner\Tests;

use Garner\Content\InvalidEntryException;
use Garner\Content\Page;
use Garner\Content\PageCollection;
use Garner\Core\Application;
use PHPUnit\Framework\TestCase;

final class RenderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/garner-test-' . bin2hex(random_bytes(6));
        $this->writeTemplates();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testRendersHomePage(): void
    {
        $this->writeEntry('', ['template' => 'home', 'created' => '2026-06-19', 'title' => 'Home']);
        $this->writeFile('routes/main.md', '# Hello world');

        $response = $this->app()->publicSite()->respond('/');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('<h1>Home</h1>', $response->body());
        self::assertStringContainsString('Hello world', $response->body());
    }

    public function testRendersNestedPage(): void
    {
        $this->writeEntry('', ['template' => 'home', 'created' => '2026-06-19', 'title' => 'Home']);
        $this->writeEntry('blog/post', [
            'template' => 'default',
            'created' => '2026-06-19',
            'title' => 'A post',
        ]);

        $response = $this->app()->publicSite()->respond('/blog/post');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('A post', $response->body());
    }

    public function testUnknownPathReturns404(): void
    {
        $this->writeEntry('', ['template' => 'home', 'created' => '2026-06-19', 'title' => 'Home']);

        $response = $this->app()->publicSite()->respond('/missing');

        self::assertSame(404, $response->status());
    }

    public function testContainerDirectoryWithoutEntryIsNotRoutable(): void
    {
        $this->writeEntry('', ['template' => 'home', 'created' => '2026-06-19', 'title' => 'Home']);
        $this->writeEntry('blog/post', ['template' => 'default', 'created' => '2026-06-19']);

        // /blog has no entry file of its own, only /blog/post does.
        self::assertSame(404, $this->app()->publicSite()->respond('/blog')->status());
    }

    public function testIdIsInheritedFromDirectoryName(): void
    {
        $this->writeEntry('', ['template' => 'home', 'created' => '2026-06-19']);
        $this->writeEntry('products/widget', ['template' => 'default', 'created' => '2026-06-19']);

        $app = $this->app();
        self::assertSame(200, $app->publicSite()->respond('/products/widget')->status());
        self::assertSame('widget', $this->indexedId($app, '/products/widget'));
    }

    public function testDuplicateIdsAcrossDirectoriesAreRejected(): void
    {
        $this->writeEntry('', ['template' => 'home', 'created' => '2026-06-19']);
        $this->writeEntry('products/clash', ['template' => 'default', 'created' => '2026-06-19']);
        $this->writeEntry('categories/clash', ['template' => 'default', 'created' => '2026-06-19']);

        $this->expectException(InvalidEntryException::class);
        $this->app()->publicSite()->respond('/products/clash');
    }

    public function testExplicitIdWinsOverDirectoryName(): void
    {
        $this->writeEntry('', ['template' => 'home', 'created' => '2026-06-19']);
        $this->writeEntry('products/widget', [
            'id' => 'explicit-id',
            'template' => 'default',
            'created' => '2026-06-19',
        ]);

        self::assertSame('explicit-id', $this->indexedId($this->app(), '/products/widget'));
    }

    public function testColocatedTemplateOverridesTemplateField(): void
    {
        $this->writeEntry('', ['template' => 'home', 'created' => '2026-06-19', 'title' => 'Home']);
        $this->writeEntry('custom', [
            'template' => 'default',
            'created' => '2026-06-19',
            'title' => 'Custom',
        ]);
        $this->writeFile('routes/custom/+template.twig', 'CO-LOCATED: {{ page.title }}');

        $response = $this->app()->publicSite()->respond('/custom');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('CO-LOCATED: Custom', $response->body());
    }

    public function testColocatedTemplateCanExtendSiteTemplate(): void
    {
        $this->writeEntry('', ['template' => 'home', 'created' => '2026-06-19', 'title' => 'Home']);
        $this->writeEntry('about', [
            'template' => 'default',
            'created' => '2026-06-19',
            'title' => 'About',
        ]);
        $this->writeFile(
            'routes/about/+template.twig',
            '{% extends "base.twig" %}{% block content %}OVERRIDDEN {{ page.title }}{% endblock %}',
        );

        $body = $this->app()->publicSite()->respond('/about')->body();

        self::assertStringContainsString('<title>About</title>', $body);
        self::assertStringContainsString('OVERRIDDEN About', $body);
    }

    public function testColocatedTemplatesDoNotCollideInOneProcess(): void
    {
        $this->writeEntry('', ['template' => 'home', 'created' => '2026-06-19', 'title' => 'Home']);
        $this->writeEntry('one', [
            'template' => 'default',
            'created' => '2026-06-19',
            'title' => 'One',
        ]);
        $this->writeFile('routes/one/+template.twig', 'PAGE ONE: {{ page.title }}');
        $this->writeEntry('two', [
            'template' => 'default',
            'created' => '2026-06-19',
            'title' => 'Two',
        ]);
        $this->writeFile('routes/two/+template.twig', 'PAGE TWO: {{ page.title }}');

        $app = $this->app();

        self::assertStringContainsString(
            'PAGE ONE: One',
            $app->publicSite()->respond('/one')->body(),
        );
        self::assertStringContainsString(
            'PAGE TWO: Two',
            $app->publicSite()->respond('/two')->body(),
        );
    }

    public function testColocatedControllerReturningResponseBypassesTwig(): void
    {
        $this->writeEntry('', ['template' => 'home', 'created' => '2026-06-19', 'title' => 'Home']);
        $this->writeEntry('api', [
            'template' => 'default',
            'created' => '2026-06-19',
            'title' => 'Api',
        ]);
        $this->writeFile(
            'routes/api/+controller.php',
            "<?php\nuse Garner\\Render\\RenderedResponse;\n"
            . "return static fn(\$page, \$site, \$app) => RenderedResponse::json(['ok' => true]);\n",
        );

        $response = $this->app()->publicSite()->respond('/api');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('application/json', $response->contentType());
        self::assertStringContainsString('"ok"', $response->body());
    }

    public function testColocatedControllerDataIsMergedIntoTemplate(): void
    {
        $this->writeEntry('', ['template' => 'home', 'created' => '2026-06-19', 'title' => 'Home']);
        $this->writeEntry('greet', [
            'template' => 'default',
            'created' => '2026-06-19',
            'title' => 'Greet',
        ]);
        $this->writeFile(
            'routes/greet/+controller.php',
            "<?php\nreturn static fn(\$page, \$site, \$app) => ['greeting' => 'Hello there'];\n",
        );
        $this->writeFile('routes/greet/+template.twig', '{{ greeting }}');

        self::assertStringContainsString(
            'Hello there',
            $this->app()->publicSite()->respond('/greet')->body(),
        );
    }

    public function testTemplateFallsBackToDefaultWhenAbsent(): void
    {
        $this->writeEntry('', ['template' => 'home', 'created' => '2026-06-19', 'title' => 'Home']);
        $this->writeEntry('plain', ['created' => '2026-06-19', 'title' => 'Plain']);
        $this->writeFile('routes/plain/main.md', 'plain body');

        $response = $this->app()->publicSite()->respond('/plain');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('<h1>Plain</h1>', $response->body());
        self::assertStringContainsString('plain body', $response->body());
    }

    public function testControllerEndpointNeedsNoTemplate(): void
    {
        $this->writeEntry('', ['template' => 'home', 'created' => '2026-06-19', 'title' => 'Home']);
        $this->writeEntry('ping', ['created' => '2026-06-19']);
        $this->writeFile(
            'routes/ping/+controller.php',
            "<?php\nuse Garner\\Render\\RenderedResponse;\n"
            . "return static fn(\$page, \$site, \$app) => RenderedResponse::json(['pong' => true]);\n",
        );

        $response = $this->app()->publicSite()->respond('/ping');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('application/json', $response->contentType());
        self::assertStringContainsString('"pong"', $response->body());
    }

    public function testSiteHomeTargetsHomePage(): void
    {
        $this->writeEntry('', ['template' => 'home', 'created' => '2026-06-19', 'title' => 'Home']);

        $app = $this->app();
        $home = $app->siteLoader()->load($app->pages())->home();

        self::assertNotNull($home);
        self::assertSame('/', $home->url());
        self::assertSame('Home', $home->title());
    }

    public function testSiteChildrenListsHomeAndImmediateChildren(): void
    {
        $this->writeEntry('', ['template' => 'home', 'created' => '2026-06-19', 'title' => 'Home']);
        $this->writeEntry('about', ['created' => '2026-06-19', 'title' => 'About']);
        $this->writeEntry('contact', ['created' => '2026-06-19', 'title' => 'Contact']);

        $app = $this->app();
        $site = $app->siteLoader()->load($app->pages());

        self::assertSame(['/', '/about', '/contact'], $this->urls($site->children()));
    }

    public function testPageChildrenExcludesSelfAndListsImmediate(): void
    {
        $this->writeEntry('', ['template' => 'home', 'created' => '2026-06-19', 'title' => 'Home']);
        $this->writeEntry('about', ['created' => '2026-06-19', 'title' => 'About']);
        $this->writeEntry('contact', ['created' => '2026-06-19', 'title' => 'Contact']);

        $home = $this->app()->pages()->home();

        // Home's own children, without home itself.
        self::assertSame(['/about', '/contact'], $this->urls($home?->children()));
    }

    public function testChildrenAreDirectOnly(): void
    {
        $this->writeEntry('', ['template' => 'home', 'created' => '2026-06-19', 'title' => 'Home']);
        $this->writeEntry('blog', ['created' => '2026-06-19', 'title' => 'Blog']);
        $this->writeEntry('blog/post', ['created' => '2026-06-19', 'title' => 'Post']);

        $app = $this->app();

        self::assertSame(['/blog'], $this->urls($app->pages()->home()?->children()));
        self::assertSame(['/blog/post'], $this->urls($app->pages()->find('/blog')?->children()));
    }

    public function testDraftPageIsNotRoutable(): void
    {
        $this->writeEntry('', ['template' => 'home', 'created' => '2026-06-19', 'title' => 'Home']);
        $this->writeEntry('wip', [
            'created' => '2026-06-19',
            'title' => 'WIP',
            'draft' => true,
        ]);

        self::assertSame(404, $this->app()->publicSite()->respond('/wip')->status());
    }

    public function testPublishedChildrenCanBeFilteredByUserField(): void
    {
        $this->writeEntry('', ['template' => 'home', 'created' => '2026-06-19', 'title' => 'Home']);
        $this->writeEntry('shown', ['created' => '2026-06-19', 'title' => 'Shown']);
        $this->writeEntry('hidden', [
            'created' => '2026-06-19',
            'title' => 'Hidden',
            'nav' => false,
        ]);

        $home = $this->app()->pages()->home();
        self::assertNotNull($home);

        // Both published pages are children, regardless of freeform fields.
        self::assertSame(['/hidden', '/shown'], $this->urls($home->children()));

        // Authors hide pages from a listing with the collection API + their own field.
        $nav = $home->children()->reject(
            static fn(Page $page): bool => $page->get('nav') === false,
        );
        self::assertSame(['/shown'], $this->urls($nav));
    }

    public function testDraftIsExcludedFromListingsButIncludableWithFlag(): void
    {
        $this->writeEntry('', ['template' => 'home', 'created' => '2026-06-19', 'title' => 'Home']);
        $this->writeEntry('live', ['created' => '2026-06-19', 'title' => 'Live']);
        $this->writeEntry('wip', ['created' => '2026-06-19', 'title' => 'WIP', 'draft' => true]);

        $home = $this->app()->pages()->home();
        self::assertNotNull($home);

        self::assertSame(['/live'], $this->urls($home->children()));
        self::assertSame(['/live', '/wip'], $this->urls($home->children(drafts: true)));
    }

    public function testDraftsCollectionFilterPartitionsChildren(): void
    {
        $this->writeEntry('', ['template' => 'home', 'created' => '2026-06-19', 'title' => 'Home']);
        $this->writeEntry('live', ['created' => '2026-06-19', 'title' => 'Live']);
        $this->writeEntry('wip', ['created' => '2026-06-19', 'title' => 'WIP', 'draft' => true]);

        $home = $this->app()->pages()->home();
        self::assertNotNull($home);

        $all = $home->children(drafts: true);
        self::assertSame(['/wip'], $this->urls($all->drafts()));
        self::assertSame(['/live'], $this->urls($all->published()));
    }

    public function testMissingDraftDefaultsToPublished(): void
    {
        $this->writeEntry('', ['template' => 'home', 'created' => '2026-06-19', 'title' => 'Home']);
        $this->writeEntry('plain', ['created' => '2026-06-19', 'title' => 'Plain']);

        $plain = $this->app()->pages()->find('/plain');

        self::assertNotNull($plain);
        self::assertFalse($plain->isDraft());
    }

    public function testDraftMustBeBoolean(): void
    {
        $this->writeEntry('', ['template' => 'home', 'created' => '2026-06-19', 'title' => 'Home']);
        $this->writeEntry('bad', ['created' => '2026-06-19', 'draft' => 'yes']);

        $this->expectException(InvalidEntryException::class);
        $this->app()->publicSite()->respond('/');
    }

    public function testChildrenAreOrderedBySort(): void
    {
        $this->writeEntry('', ['template' => 'home', 'created' => '2026-06-19', 'title' => 'Home']);
        $this->writeEntry('alpha', ['created' => '2026-06-19', 'title' => 'Alpha', 'sort' => 30]);
        $this->writeEntry('bravo', ['created' => '2026-06-19', 'title' => 'Bravo', 'sort' => 10]);
        $this->writeEntry('charlie', [
            'created' => '2026-06-19',
            'title' => 'Charlie',
            'sort' => 20,
        ]);

        $urls = $this->urls($this->app()->pages()->home()?->children());

        // sort order wins over alphabetical path order.
        self::assertSame(['/bravo', '/charlie', '/alpha'], $urls);
    }

    public function testNegativeSortPinsAboveUnsetAndPositiveSinks(): void
    {
        $this->writeEntry('', ['template' => 'home', 'created' => '2026-06-19', 'title' => 'Home']);
        $this->writeEntry('top', ['created' => '2026-06-19', 'sort' => -20]);
        $this->writeEntry('second', ['created' => '2026-06-19', 'sort' => -10]);
        $this->writeEntry('apple', ['created' => '2026-06-19']);
        $this->writeEntry('mango', ['created' => '2026-06-19']);
        $this->writeEntry('last', ['created' => '2026-06-19', 'sort' => 100]);

        $urls = $this->urls($this->app()->pages()->home()?->children());

        // Negatives pin to the top; unset (= 0) sit in the middle by path; positives sink.
        self::assertSame(['/top', '/second', '/apple', '/mango', '/last'], $urls);
    }

    public function testMissingSortDefaultsToZero(): void
    {
        $this->writeEntry('', ['template' => 'home', 'created' => '2026-06-19', 'title' => 'Home']);
        $this->writeEntry('plain', ['created' => '2026-06-19', 'title' => 'Plain']);

        $plain = $this->app()->pages()->find('/plain');

        self::assertNotNull($plain);
        self::assertSame(0, $plain->sort());
    }

    public function testSortMustBeAnInteger(): void
    {
        $this->writeEntry('', ['template' => 'home', 'created' => '2026-06-19', 'title' => 'Home']);
        $this->writeEntry('bad', ['created' => '2026-06-19', 'sort' => 'high']);

        $this->expectException(InvalidEntryException::class);
        $this->app()->publicSite()->respond('/');
    }

    public function testEntryNeedsNoRequiredFields(): void
    {
        $this->writeEntry('', ['template' => 'home', 'created' => '2026-06-19', 'title' => 'Home']);
        $this->writeEntry('bare', ['title' => 'Bare']);

        $app = $this->app();

        self::assertSame(200, $app->publicSite()->respond('/bare')->status());

        $bare = $app->pages()->find('/bare');
        self::assertNotNull($bare);
        self::assertNull($bare->created());
        self::assertSame('bare', $bare->id());
        self::assertFalse($bare->isDraft());
    }

    public function testCreatedMustBeAStringWhenPresent(): void
    {
        $this->writeEntry('', ['template' => 'home', 'created' => '2026-06-19', 'title' => 'Home']);
        $this->writeEntry('bad', ['created' => 123]);

        $this->expectException(InvalidEntryException::class);
        $this->app()->publicSite()->respond('/');
    }

    public function testErrorTemplateSelectionIsStatusAware(): void
    {
        $this->writeEntry('', ['template' => 'home', 'created' => '2026-06-19', 'title' => 'Home']);

        $app = $this->app();
        $site = $app->siteLoader()->load($app->pages());
        $renderer = $app->siteRenderer();

        // A 404 still renders the 404-specific template.
        $notFound = $renderer->renderError($site, 404, 'not_found', ['path' => '/missing']);
        self::assertStringContainsString('Not found: /missing', $notFound);

        // A 500 must NOT reuse the 404 template; it falls back to generic markup.
        $appError = $renderer->renderError($site, 500, 'application_error', ['path' => '/boom']);
        self::assertStringNotContainsString('Not found:', $appError);
        self::assertStringContainsString('could not be completed', $appError);
    }

    public function testIndexTreatsUnderscoreInPathLiterally(): void
    {
        $this->writeEntry('', ['template' => 'home', 'created' => '2026-06-19', 'title' => 'Home']);
        $this->writeEntry('foo_bar', ['created' => '2026-06-19', 'title' => 'Foo bar']);
        $this->writeEntry('foo_bar/real', ['created' => '2026-06-19', 'title' => 'Real child']);
        $this->writeEntry('fooxbar', ['created' => '2026-06-19', 'title' => 'Decoy']);
        $this->writeEntry('fooxbar/decoy', ['created' => '2026-06-19', 'title' => 'Decoy child']);

        $page = $this->app()->pages()->find('/foo_bar');
        self::assertNotNull($page);

        // "_" must be a literal, not a SQL single-char wildcard that also matches /fooxbar.
        self::assertSame(['/foo_bar/real'], $this->urls($page->index()));
    }

    public function testFindByIdResolvesIndependentOfRoute(): void
    {
        $this->writeEntry('', ['template' => 'home', 'created' => '2026-06-19', 'title' => 'Home']);
        $this->writeEntry('deep/nested/widget', [
            'id' => 'widget-id',
            'created' => '2026-06-19',
            'title' => 'Widget',
        ]);

        $page = $this->app()->pages()->findById('widget-id');

        self::assertNotNull($page);
        self::assertSame('/deep/nested/widget', $page->url());
        self::assertSame('Widget', $page->title());
    }

    public function testFindByIdReturnsNullForUnknownId(): void
    {
        $this->writeEntry('', ['template' => 'home', 'created' => '2026-06-19', 'title' => 'Home']);

        self::assertNull($this->app()->pages()->findById('does-not-exist'));
    }

    public function testFindByIdSkipsDraftPages(): void
    {
        $this->writeEntry('', ['template' => 'home', 'created' => '2026-06-19', 'title' => 'Home']);
        $this->writeEntry('wip', [
            'id' => 'wip-id',
            'created' => '2026-06-19',
            'draft' => true,
        ]);

        self::assertNull($this->app()->pages()->findById('wip-id'));
    }

    public function testSiteFindByIdResolvesReference(): void
    {
        $this->writeEntry('', ['template' => 'home', 'created' => '2026-06-19', 'title' => 'Home']);
        $this->writeEntry('about', [
            'id' => 'about-id',
            'created' => '2026-06-19',
            'title' => 'About',
        ]);

        $app = $this->app();
        $site = $app->siteLoader()->load($app->pages());

        self::assertSame('/about', $site->findById('about-id')?->url());
    }

    private function app(): Application
    {
        return new Application($this->root, $this->root, [
            'app' => ['debug' => true, 'name' => 'Test Site'],
        ]);
    }

    /**
     * @return list<string>
     */
    private function urls(?PageCollection $pages): array
    {
        if ($pages === null) {
            return [];
        }

        $urls = [];

        foreach ($pages as $page) {
            $urls[] = $page->url();
        }

        return $urls;
    }

    private function indexedId(Application $app, string $path): ?string
    {
        // Force a build, then read the stored id back from the derived index.
        $app->contentIndex()->dirForPath($path);
        $pdo = new \PDO('sqlite:' . $this->root . '/runtime/index.sqlite');
        $statement = $pdo->prepare('SELECT id FROM pages WHERE path = :path');
        $statement->execute([':path' => $path]);
        $id = $statement->fetchColumn();

        return is_string($id) ? $id : null;
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

    private function writeTemplates(): void
    {
        $page = "<h1>{{ page.title }}</h1>\n{{ content.main|markdown }}";
        $this->writeFile('app/templates/home.twig', $page);
        $this->writeFile('app/templates/default.twig', $page);
        $this->writeFile('app/templates/404.twig', 'Not found: {{ error.path }}');
        $this->writeFile(
            'app/templates/base.twig',
            '<title>{% block title %}{{ page.title }}{% endblock %}</title>'
            . '<body>{% block content %}{{ content.main|markdown }}{% endblock %}</body>',
        );
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
