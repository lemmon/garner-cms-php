<?php

declare(strict_types=1);

use Garner\Content\PageRepository;
use Garner\Content\PathIndexer;
use Garner\Content\PathResolver;
use Garner\Content\SiteRepository;
use PHPUnit\Framework\TestCase;

final class ContentRepositoryTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/garner-cms-test-' . bin2hex(random_bytes(6));
        mkdir($this->projectRoot . '/content/pages', 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->projectRoot);
    }

    public function testPageRepositorySavesSnakeCasePageJson(): void
    {
        $pages = new PageRepository($this->projectRoot . '/content');

        $pages->save([
            'id' => 'page-home',
            'status' => 'listed',
            'fields' => [
                'title' => 'Home',
            ],
        ]);

        $stored = json_decode(
            (string) file_get_contents($this->projectRoot . '/content/pages/page-home/+page.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        if (!is_array($stored)) {
            self::fail('Stored page JSON must decode to an array.');
        }

        self::assertSame('page-home', $stored['id']);
        self::assertArrayHasKey('parent_id', $stored);
        self::assertArrayHasKey('slug', $stored);
        self::assertArrayHasKey('created_at', $stored);
        self::assertNull($stored['slug']);
        self::assertSame('page', $stored['blueprint']);
        self::assertSame('default', $stored['template']);
        self::assertSame('listed', $stored['status']);
    }

    public function testPageRepositoryDropsSortForUnlistedPages(): void
    {
        $pages = new PageRepository($this->projectRoot . '/content');

        $pages->save([
            'id' => 'privacy-page',
            'slug' => 'privacy',
            'status' => 'unlisted',
            'sort' => 20,
            'fields' => [
                'title' => 'Privacy',
            ],
        ]);

        $stored = $pages->find('privacy-page');

        if (!is_array($stored)) {
            self::fail('Stored page JSON must decode to an array.');
        }

        self::assertSame('unlisted', $stored['status']);
        self::assertNull($stored['sort']);
    }

    public function testPageRepositoryCanonicalizesBlueprintAndTemplateIdentifiers(): void
    {
        $pages = new PageRepository($this->projectRoot . '/content');

        $pages->save([
            'id' => 'controller-page',
            'blueprint' => 'controller_response',
            'template' => 'controller_response',
            'fields' => [
                'title' => 'Controller Response',
            ],
        ]);

        $stored = $pages->find('controller-page');

        if (!is_array($stored)) {
            self::fail('Stored page JSON must decode to an array.');
        }

        self::assertSame('controller-response', $stored['blueprint']);
        self::assertSame('controller-response', $stored['template']);
    }

    public function testIndexerBuildsPublicPathsAndResolverSkipsDrafts(): void
    {
        $pages = new PageRepository($this->projectRoot . '/content');
        $site = new SiteRepository($this->projectRoot . '/content');

        $site->save([
            'home_page_id' => 'home-page',
            'title' => 'Test Site',
        ]);

        $pages->save([
            'id' => 'home-page',
            'slug' => 'home',
            'status' => 'listed',
            'sort' => 1,
            'fields' => [
                'title' => 'Home',
            ],
        ]);

        $pages->save([
            'id' => 'about-page',
            'parent_id' => 'home-page',
            'slug' => 'about',
            'status' => 'listed',
            'sort' => 10,
            'fields' => [
                'title' => 'About',
            ],
        ]);

        $pages->save([
            'id' => 'privacy-page',
            'parent_id' => 'home-page',
            'slug' => 'privacy',
            'status' => 'unlisted',
            'sort' => 20,
            'fields' => [
                'title' => 'Privacy',
            ],
        ]);

        $pages->save([
            'id' => 'generated-page',
            'parent_id' => 'home-page',
            'status' => 'listed',
            'sort' => 25,
            'fields' => [
                'title' => 'Generated',
            ],
        ]);

        $pages->save([
            'id' => 'draft-page',
            'parent_id' => 'home-page',
            'slug' => 'draft',
            'status' => 'draft',
            'sort' => 30,
            'fields' => [
                'title' => 'Draft',
            ],
        ]);

        $indexer = new PathIndexer(
            siteRepository: $site,
            pageRepository: $pages,
            sqlitePath: $this->projectRoot . '/runtime/index.sqlite',
        );

        $stats = $indexer->rebuild();
        $resolver = new PathResolver(
            sqlitePath: $this->projectRoot . '/runtime/index.sqlite',
            pageRepository: $pages,
        );

        self::assertSame(5, $stats['entry_count']);
        self::assertSame(4, $stats['path_count']);

        self::assertSame('home-page', $resolver->resolve('/')['id']);
        self::assertSame('about-page', $resolver->resolve('/about')['id']);
        self::assertSame('privacy-page', $resolver->resolve('/privacy')['id']);
        self::assertSame('generated-page', $resolver->resolve('/generated-page')['id']);
        self::assertNull($resolver->resolve('/draft'));
    }

    public function testRebuildReflectsSlugAndStatusChangesAcrossDescendants(): void
    {
        $pages = new PageRepository($this->projectRoot . '/content');
        $site = new SiteRepository($this->projectRoot . '/content');

        $site->save([
            'home_page_id' => 'home-page',
            'title' => 'Test Site',
        ]);

        $pages->save([
            'id' => 'home-page',
            'slug' => 'home',
            'status' => 'listed',
            'sort' => 1,
            'fields' => [
                'title' => 'Home',
            ],
        ]);

        $pages->save([
            'id' => 'section-page',
            'parent_id' => 'home-page',
            'slug' => 'about',
            'status' => 'listed',
            'sort' => 10,
            'fields' => [
                'title' => 'About',
            ],
        ]);

        $pages->save([
            'id' => 'team-page',
            'parent_id' => 'section-page',
            'slug' => 'team',
            'status' => 'listed',
            'sort' => 20,
            'fields' => [
                'title' => 'Team',
            ],
        ]);

        $indexer = new PathIndexer(
            siteRepository: $site,
            pageRepository: $pages,
            sqlitePath: $this->projectRoot . '/runtime/index.sqlite',
        );

        $resolver = new PathResolver(
            sqlitePath: $this->projectRoot . '/runtime/index.sqlite',
            pageRepository: $pages,
        );

        $indexer->rebuild();

        self::assertSame('section-page', $resolver->resolve('/about')['id']);
        self::assertSame('team-page', $resolver->resolve('/about/team')['id']);

        $pages->save([
            'id' => 'section-page',
            'parent_id' => 'home-page',
            'slug' => 'company',
            'status' => 'listed',
            'sort' => 10,
            'fields' => [
                'title' => 'About',
            ],
        ]);

        $indexer->rebuild();

        self::assertNull($resolver->resolve('/about'));
        self::assertNull($resolver->resolve('/about/team'));
        self::assertSame('section-page', $resolver->resolve('/company')['id']);
        self::assertSame('team-page', $resolver->resolve('/company/team')['id']);

        $pages->save([
            'id' => 'section-page',
            'parent_id' => 'home-page',
            'slug' => 'company',
            'status' => 'draft',
            'sort' => 10,
            'fields' => [
                'title' => 'About',
            ],
        ]);

        $indexer->rebuild();

        self::assertNull($resolver->resolve('/company'));
        self::assertNull($resolver->resolve('/company/team'));
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
