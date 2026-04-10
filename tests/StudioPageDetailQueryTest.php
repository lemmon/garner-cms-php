<?php

declare(strict_types=1);

use Garner\Blueprint\BlueprintLoader;
use Garner\Content\PageRepository;
use Garner\Content\PathIndexer;
use Garner\Content\PathResolver;
use Garner\Content\SiteRepository;
use Garner\Studio\PageDetailQuery;
use PHPUnit\Framework\TestCase;

final class StudioPageDetailQueryTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot =
            sys_get_temp_dir() . '/garner-cms-studio-page-detail-' . bin2hex(random_bytes(6));

        mkdir($this->projectRoot . '/content/pages', 0o777, true);
        mkdir($this->projectRoot . '/site/blueprints/pages', 0o777, true);

        file_put_contents($this->projectRoot . '/site/blueprints/pages/page.yml', <<<'YAML'
            title: Page
            tabs: []
            YAML);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->projectRoot);
    }

    public function testPageDetailQueryReturnsReadOnlyPageDetail(): void
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
            'id' => 'about-page',
            'parent_id' => 'home-page',
            'slug' => 'about',
            'status' => 'listed',
            'sort' => 10,
            'fields' => [
                'title' => 'About',
                'text' => 'About Garner',
            ],
        ]);

        (new PathIndexer(
            siteRepository: $siteRepository,
            pageRepository: $pageRepository,
            sqlitePath: $this->projectRoot . '/runtime/index.sqlite',
        ))->rebuild();

        $query = new PageDetailQuery(
            siteRepository: $siteRepository,
            pageRepository: $pageRepository,
            pathResolver: new PathResolver(
                sqlitePath: $this->projectRoot . '/runtime/index.sqlite',
                pageRepository: $pageRepository,
            ),
            blueprintLoader: new BlueprintLoader($this->projectRoot . '/site/blueprints'),
        );

        $payload = $query->query([
            'id' => 'about-page',
        ]);

        self::assertTrue($payload['ok']);
        self::assertSame('about-page', $payload['page']['id']);
        self::assertSame('About', $payload['page']['title']);
        self::assertSame('page', $payload['page']['blueprint']);
        self::assertSame('/about', $payload['page']['path']);
        self::assertSame(0, $payload['page']['children_count']);
        self::assertSame(
            [
                'title' => 'About',
                'text' => 'About Garner',
            ],
            $payload['page']['fields'],
        );
        self::assertSame(
            [
                [
                    'label' => 'Site',
                    'href' => '/site',
                ],
                [
                    'label' => 'Home',
                    'href' => '/site/pages/home-page',
                ],
                [
                    'label' => 'About',
                    'href' => null,
                ],
            ],
            $payload['breadcrumbs'],
        );
        self::assertFalse($payload['page']['is_system']);
        self::assertTrue($payload['page']['slug_editable']);
        self::assertSame('Page', $payload['blueprint']['title']);
        self::assertNull($payload['blueprint_issue']);
    }

    public function testPageDetailMarksSystemPageSlugAsNotEditable(): void
    {
        $siteRepository = new SiteRepository($this->projectRoot . '/content');
        $pageRepository = new PageRepository($this->projectRoot . '/content');

        $siteRepository->save([
            'title' => 'Test Garner',
            'home_page_id' => 'home-page',
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

        (new PathIndexer(
            siteRepository: $siteRepository,
            pageRepository: $pageRepository,
            sqlitePath: $this->projectRoot . '/runtime/index.sqlite',
        ))->rebuild();

        $query = new PageDetailQuery(
            siteRepository: $siteRepository,
            pageRepository: $pageRepository,
            pathResolver: new PathResolver(
                sqlitePath: $this->projectRoot . '/runtime/index.sqlite',
                pageRepository: $pageRepository,
            ),
            blueprintLoader: new BlueprintLoader($this->projectRoot . '/site/blueprints'),
        );

        $payload = $query->query([
            'id' => 'home-page',
        ]);

        self::assertTrue($payload['page']['is_system']);
        self::assertFalse($payload['page']['slug_editable']);
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
