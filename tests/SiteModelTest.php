<?php

declare(strict_types=1);

use Garner\Content\PageRepository;
use Garner\Content\PathIndexer;
use Garner\Content\PathResolver;
use Garner\Content\SiteRepository;
use Garner\Site\Pages;
use Garner\Site\Site;
use PHPUnit\Framework\TestCase;

final class SiteModelTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot =
            sys_get_temp_dir() . '/garner-cms-site-model-' . bin2hex(random_bytes(6));

        mkdir($this->projectRoot . '/content/pages', 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->projectRoot);
    }

    public function testSiteAndPageTreeQueriesFollowTheDocumentedSemantics(): void
    {
        $siteRepository = new SiteRepository($this->projectRoot . '/content');
        $pageRepository = new PageRepository($this->projectRoot . '/content');

        $siteRepository->save([
            'title' => 'Test Garner',
            'home_page_id' => 'home-page',
            'error_page_id' => 'error-page',
        ]);

        $pageRepository->save([
            'id' => 'home-page',
            'blueprint' => 'home',
            'template' => 'home',
            'status' => null,
            'slug' => 'home',
            'fields' => [
                'title' => 'Home',
            ],
        ]);

        $pageRepository->save([
            'id' => 'contact-page',
            'parent_id' => 'home-page',
            'slug' => 'contact',
            'status' => 'listed',
            'sort' => 5,
            'template' => 'default',
            'fields' => [
                'title' => 'Contact',
            ],
        ]);

        $pageRepository->save([
            'id' => 'about-page',
            'parent_id' => 'home-page',
            'slug' => 'about',
            'status' => 'listed',
            'sort' => 10,
            'template' => 'default',
            'fields' => [
                'title' => 'About',
            ],
        ]);

        $pageRepository->save([
            'id' => 'team-page',
            'parent_id' => 'about-page',
            'slug' => 'team',
            'status' => 'listed',
            'sort' => 10,
            'template' => 'default',
            'fields' => [
                'title' => 'Team',
            ],
        ]);

        $pageRepository->save([
            'id' => 'privacy-page',
            'parent_id' => 'home-page',
            'slug' => 'privacy',
            'status' => 'unlisted',
            'sort' => 1,
            'template' => 'default',
            'fields' => [
                'title' => 'Privacy',
            ],
        ]);

        $pageRepository->save([
            'id' => 'zed-page',
            'parent_id' => 'home-page',
            'slug' => 'zed',
            'status' => 'unlisted',
            'sort' => 0,
            'template' => 'default',
            'fields' => [
                'title' => 'Zed',
            ],
        ]);

        $pageRepository->save([
            'id' => 'alpha-draft-page',
            'parent_id' => 'home-page',
            'status' => 'draft',
            'sort' => 1,
            'template' => 'default',
            'fields' => [
                'title' => 'Alpha Draft',
            ],
        ]);

        $pageRepository->save([
            'id' => 'draft-page',
            'parent_id' => 'home-page',
            'slug' => 'draft',
            'status' => 'draft',
            'sort' => 20,
            'template' => 'default',
            'fields' => [
                'title' => 'Draft',
            ],
        ]);

        $pageRepository->save([
            'id' => 'error-page',
            'blueprint' => 'error',
            'template' => 'error',
            'status' => null,
            'fields' => [
                'title' => 'Not Found',
            ],
        ]);

        (new PathIndexer(
            siteRepository: $siteRepository,
            pageRepository: $pageRepository,
            sqlitePath: $this->projectRoot . '/runtime/index.sqlite',
        ))->rebuild();

        $pages = new Pages(
            pageRepository: $pageRepository,
            pathResolver: new PathResolver(
                sqlitePath: $this->projectRoot . '/runtime/index.sqlite',
                pageRepository: $pageRepository,
            ),
        );
        $site = new Site($siteRepository->read(), $pages);
        $home = $site->home();

        self::assertNotNull($home);
        self::assertNull($home->status());
        self::assertSame(
            ['home-page', 'contact-page', 'about-page', 'privacy-page', 'zed-page'],
            $site->children()->map(static fn($page): string => $page->id())->all(),
        );
        self::assertSame(
            [
                'home-page',
                'contact-page',
                'about-page',
                'privacy-page',
                'zed-page',
                'alpha-draft-page',
                'draft-page',
            ],
            $site->children(true)->map(static fn($page): string => $page->id())->all(),
        );
        self::assertSame(
            [
                'home-page',
                'contact-page',
                'about-page',
                'team-page',
                'privacy-page',
                'zed-page',
            ],
            $site->index()->map(static fn($page): string => $page->id())->all(),
        );
        self::assertSame(
            [
                'home-page',
                'contact-page',
                'about-page',
                'team-page',
                'privacy-page',
                'zed-page',
                'alpha-draft-page',
                'draft-page',
            ],
            $site->index(true)->map(static fn($page): string => $page->id())->all(),
        );
        self::assertSame(
            ['contact-page', 'about-page', 'privacy-page', 'zed-page'],
            $home->children()->map(static fn($page): string => $page->id())->all(),
        );
        self::assertSame(
            [
                'contact-page',
                'about-page',
                'privacy-page',
                'zed-page',
                'alpha-draft-page',
                'draft-page',
            ],
            $home->children(true)->map(static fn($page): string => $page->id())->all(),
        );
        self::assertSame(
            ['contact-page', 'about-page', 'team-page', 'privacy-page', 'zed-page'],
            $home->index()->map(static fn($page): string => $page->id())->all(),
        );
        self::assertSame(
            [
                'contact-page',
                'about-page',
                'team-page',
                'privacy-page',
                'zed-page',
                'alpha-draft-page',
                'draft-page',
            ],
            $home->index(true)->map(static fn($page): string => $page->id())->all(),
        );
        self::assertSame(
            ['error-page'],
            $site->systemPages()->map(static fn($page): string => $page->id())->all(),
        );
        self::assertNull($pages->find('error-page')?->status());

        $privacy = $pages->find('privacy-page');
        $zed = $pages->find('zed-page');

        self::assertNotNull($privacy);
        self::assertNotNull($zed);
        self::assertNull($privacy->data()['sort'] ?? null);
        self::assertNull($zed->data()['sort'] ?? null);
    }

    public function testSiteIdentifiesSystemPagesByConfiguredPointers(): void
    {
        $site = new Site([
            'title' => 'Test',
            'home_page_id' => 'home-page',
            'error_page_id' => 'error-page',
        ]);

        self::assertTrue($site->isSystemPage('home-page'));
        self::assertTrue($site->isSystemPage('error-page'));
        self::assertFalse($site->isSystemPage('about-page'));
        self::assertFalse($site->isSystemPage(''));
    }

    public function testSiteIdentifiesSystemPagesWithoutErrorPage(): void
    {
        $site = new Site([
            'title' => 'Test',
            'home_page_id' => 'home-page',
        ]);

        self::assertTrue($site->isSystemPage('home-page'));
        self::assertFalse($site->isSystemPage('error-page'));
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
