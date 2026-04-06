<?php

declare(strict_types=1);

use Garner\Content\PageRepository;
use Garner\Content\PathIndexer;
use Garner\Content\PathResolver;
use Garner\Content\SiteRepository;
use Garner\Studio\NodeQuery;
use Garner\Studio\PageListItemSerializer;
use Garner\Studio\PageListQuery;
use PHPUnit\Framework\TestCase;

final class StudioNodeQueryTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot =
            sys_get_temp_dir() . '/garner-cms-studio-node-query-' . bin2hex(random_bytes(6));

        mkdir($this->projectRoot . '/content/pages', 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->projectRoot);
    }

    public function testPageListDefaultsToSourceChildrenForSite(): void
    {
        $query = $this->makeQuery();

        $result = $query->query([
            'type' => 'page_list',
            'source' => 'site',
        ]);

        self::assertTrue($result['ok']);
        self::assertSame('source.children(drafts: true)', $result['query']);
        self::assertSame(
            ['home-page', 'contact-page', 'about-page', 'privacy-page', 'draft-page'],
            array_column($result['items'], 'id'),
        );
        self::assertSame([0, 1, 1, 1, 1], array_column($result['items'], 'relative_depth'));
        self::assertSame(
            [null, 'listed', 'listed', 'unlisted', 'draft'],
            array_column($result['items'], 'status'),
        );
    }

    public function testPageListCanQueryDifferentSourcesAndSystemPages(): void
    {
        $query = $this->makeQuery();

        $homeChildren = $query->query([
            'type' => 'page_list',
            'source' => 'site.home',
        ]);
        $aboutIndex = $query->query([
            'type' => 'page_list',
            'source' => 'site.page("about-page")',
            'query' => 'source.index(drafts: true)',
        ]);
        $systemPages = $query->query([
            'type' => 'page_list',
            'source' => 'site',
            'query' => 'source.system_pages',
        ]);

        self::assertSame(
            ['contact-page', 'about-page', 'privacy-page', 'draft-page'],
            array_column($homeChildren['items'], 'id'),
        );
        self::assertSame([0, 0, 0, 0], array_column($homeChildren['items'], 'relative_depth'));
        self::assertSame(['team-page'], array_column($aboutIndex['items'], 'id'));
        self::assertSame(['error-page'], array_column($systemPages['items'], 'id'));
        self::assertTrue($systemPages['items'][0]['is_error']);
    }

    private function makeQuery(): NodeQuery
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
            'slug' => 'home',
            'sort' => 1,
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
            'fields' => [
                'title' => 'Not Found',
            ],
        ]);

        (new PathIndexer(
            siteRepository: $siteRepository,
            pageRepository: $pageRepository,
            sqlitePath: $this->projectRoot . '/runtime/index.sqlite',
        ))->rebuild();

        return new NodeQuery(new PageListQuery(
            siteRepository: $siteRepository,
            pageRepository: $pageRepository,
            pathResolver: new PathResolver(
                sqlitePath: $this->projectRoot . '/runtime/index.sqlite',
                pageRepository: $pageRepository,
            ),
            serializer: new PageListItemSerializer($pageRepository),
        ));
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
