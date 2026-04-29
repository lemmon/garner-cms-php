<?php

declare(strict_types=1);

use Garner\Content\PageRepository;
use Garner\Content\PathIndexer;
use Garner\Content\PathResolver;
use Garner\Content\SiteRepository;
use Garner\Studio\PageCreate;
use Garner\Support\IdGenerator;
use PHPUnit\Framework\TestCase;

final class StudioPageCreateTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot =
            sys_get_temp_dir() . '/garner-cms-studio-page-create-' . bin2hex(random_bytes(6));

        mkdir($this->projectRoot . '/content/pages', 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->projectRoot);
    }

    public function testPageCreateCreatesDraftUnderSiteHome(): void
    {
        [$pageRepository, $creator] = $this->seedSite();

        $result = $creator->create([
            'source' => 'site',
            'title' => 'New Draft',
            'slug' => 'new-draft',
        ]);

        $createdPageId = $result['page']['id'];

        if (!is_string($createdPageId)) {
            self::fail('Created page id must be a string.');
        }

        $stored = $pageRepository->find($createdPageId);

        if (!is_array($stored)) {
            self::fail('Created page must exist.');
        }

        self::assertTrue($result['ok']);
        self::assertSame('generated-page-id', $createdPageId);
        self::assertSame('home-page', $stored['parent_id']);
        self::assertSame('new-draft', $stored['slug']);
        self::assertSame('draft', $stored['status']);
        self::assertSame('default', $stored['blueprint']);
        self::assertSame('default', $stored['template']);
        self::assertSame('New Draft', $stored['fields']['title']);
        self::assertNull($result['page']['path']);
    }

    public function testPageCreateUsesPageSourceAsParentAndRetriesIdCollisions(): void
    {
        [$pageRepository, $creator] = $this->seedSite();

        $pageRepository->save([
            'id' => 'generated-page-id',
            'parent_id' => 'home-page',
            'slug' => 'collision',
            'status' => 'draft',
            'fields' => [
                'title' => 'Collision',
            ],
        ]);

        $result = $creator->create([
            'source' => 'site.page("about-page")',
            'title' => 'About',
            'slug' => 'about',
        ]);

        $createdPageId = $result['page']['id'];

        if (!is_string($createdPageId)) {
            self::fail('Created page id must be a string.');
        }

        $stored = $pageRepository->find($createdPageId);

        if (!is_array($stored)) {
            self::fail('Created page must exist.');
        }

        self::assertSame('generated-page-id-2', $createdPageId);
        self::assertSame('about-page', $stored['parent_id']);
        self::assertSame('about', $stored['slug']);
        self::assertSame('draft', $stored['status']);
    }

    public function testPageCreateCanReportSiblingSlugConflicts(): void
    {
        [, $creator] = $this->seedSite();

        self::assertTrue($creator->slugExistsAmongSiblingsForSource('site', 'about'));
        self::assertFalse($creator->slugExistsAmongSiblingsForSource(
            'site.page("about-page")',
            'about',
        ));
    }

    /**
     * @return array{0: PageRepository, 1: PageCreate}
     */
    private function seedSite(): array
    {
        $siteRepository = new SiteRepository($this->projectRoot . '/content');
        $pageRepository = new PageRepository($this->projectRoot . '/content');
        $sqlitePath = $this->projectRoot . '/runtime/index.sqlite';

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

        $pageRepository->save([
            'id' => 'about-page',
            'parent_id' => 'home-page',
            'slug' => 'about',
            'status' => 'listed',
            'sort' => 10,
            'fields' => [
                'title' => 'About',
            ],
        ]);

        (new PathIndexer(
            siteRepository: $siteRepository,
            pageRepository: $pageRepository,
            sqlitePath: $sqlitePath,
        ))->rebuild();

        return [
            $pageRepository,
            new PageCreate(
                siteRepository: $siteRepository,
                pageRepository: $pageRepository,
                pathIndexer: new PathIndexer(
                    siteRepository: $siteRepository,
                    pageRepository: $pageRepository,
                    sqlitePath: $sqlitePath,
                ),
                pathResolver: new PathResolver(
                    sqlitePath: $sqlitePath,
                    pageRepository: $pageRepository,
                ),
                idGenerator: $this->idGenerator('generated-page-id', 'generated-page-id-2'),
            ),
        ];
    }

    private function idGenerator(string ...$ids): IdGenerator
    {
        $ids = array_values($ids);

        return new class($ids) implements IdGenerator {
            private int $index = 0;

            /**
             * @param list<string> $ids
             */
            public function __construct(
                private readonly array $ids,
            ) {}

            public function generate(): string
            {
                return $this->ids[$this->index++] ?? throw new RuntimeException('No test IDs left');
            }
        };
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
