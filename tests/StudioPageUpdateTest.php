<?php

declare(strict_types=1);

use Garner\Content\PageRepository;
use Garner\Content\PathIndexer;
use Garner\Content\PathResolver;
use Garner\Content\SiteRepository;
use Garner\Studio\PageUpdate;
use PHPUnit\Framework\TestCase;

final class StudioPageUpdateTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot =
            sys_get_temp_dir() . '/garner-cms-studio-page-update-' . bin2hex(random_bytes(6));

        mkdir($this->projectRoot . '/content/pages', 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->projectRoot);
    }

    public function testPageUpdateChangesTitleAndSlugForNormalPages(): void
    {
        [$siteRepository, $pageRepository, $pathResolver] = $this->seedSite();

        $page = $pageRepository->findOrFail('about-page');

        $result = (new PageUpdate(
            siteRepository: $siteRepository,
            pageRepository: $pageRepository,
            pathIndexer: new PathIndexer(
                siteRepository: $siteRepository,
                pageRepository: $pageRepository,
                sqlitePath: $this->projectRoot . '/runtime/index.sqlite',
            ),
            pathResolver: $pathResolver,
        ))->update(page: $page, validated: ['title' => 'Company Name', 'slug' => 'company-name']);

        $stored = $pageRepository->find('about-page');

        if (!is_array($stored)) {
            self::fail('Updated page must exist.');
        }

        self::assertTrue($result['ok']);
        self::assertSame('Company Name', $stored['fields']['title']);
        self::assertSame('company-name', $stored['slug']);
        self::assertSame('/company-name', $result['page']['path']);
    }

    public function testPageUpdateDoesNotChangeSlugWhenOmitted(): void
    {
        [$siteRepository, $pageRepository, $pathResolver] = $this->seedSite();

        $page = $pageRepository->findOrFail('home-page');

        (new PageUpdate(
            siteRepository: $siteRepository,
            pageRepository: $pageRepository,
            pathIndexer: new PathIndexer(
                siteRepository: $siteRepository,
                pageRepository: $pageRepository,
                sqlitePath: $this->projectRoot . '/runtime/index.sqlite',
            ),
            pathResolver: $pathResolver,
        ))->update(page: $page, validated: ['title' => 'Welcome']);

        $stored = $pageRepository->find('home-page');

        if (!is_array($stored)) {
            self::fail('Updated home page must exist.');
        }

        self::assertSame('Welcome', $stored['fields']['title']);
        self::assertSame('home', $stored['slug']);
        self::assertNull($stored['status']);
    }

    public function testPageUpdateCanPersistSupportedFieldValues(): void
    {
        [$siteRepository, $pageRepository, $pathResolver] = $this->seedSite();

        $page = $pageRepository->findOrFail('about-page');

        $result = (new PageUpdate(
            siteRepository: $siteRepository,
            pageRepository: $pageRepository,
            pathIndexer: new PathIndexer(
                siteRepository: $siteRepository,
                pageRepository: $pageRepository,
                sqlitePath: $this->projectRoot . '/runtime/index.sqlite',
            ),
            pathResolver: $pathResolver,
        ))->update(page: $page, validated: ['text' => "Updated\nbody"]);

        $stored = $pageRepository->find('about-page');

        if (!is_array($stored)) {
            self::fail('Updated page must exist.');
        }

        self::assertTrue($result['ok']);
        self::assertSame('About', $stored['fields']['title']);
        self::assertSame("Updated\nbody", $stored['fields']['text']);
        self::assertSame("Updated\nbody", $result['page']['fields']['text']);
        self::assertSame('/about', $result['page']['path']);
    }

    public function testPageUpdateCanApplyMixedPayload(): void
    {
        [$siteRepository, $pageRepository, $pathResolver] = $this->seedSite();

        $page = $pageRepository->findOrFail('about-page');

        $result = (new PageUpdate(
            siteRepository: $siteRepository,
            pageRepository: $pageRepository,
            pathIndexer: new PathIndexer(
                siteRepository: $siteRepository,
                pageRepository: $pageRepository,
                sqlitePath: $this->projectRoot . '/runtime/index.sqlite',
            ),
            pathResolver: $pathResolver,
        ))->update(page: $page, validated: [
            'title' => 'New Title',
            'slug' => 'new-slug',
            'text' => 'New body',
        ]);

        $stored = $pageRepository->find('about-page');

        if (!is_array($stored)) {
            self::fail('Updated page must exist.');
        }

        self::assertTrue($result['ok']);
        self::assertSame('New Title', $stored['fields']['title']);
        self::assertSame('New body', $stored['fields']['text']);
        self::assertSame('new-slug', $stored['slug']);
        self::assertSame('/new-slug', $result['page']['path']);
        self::assertSame('New Title', $result['page']['title']);
        self::assertSame('New body', $result['page']['fields']['text']);
    }

    public function testPageUpdateCanReportSiblingSlugConflicts(): void
    {
        [$siteRepository, $pageRepository, $pathResolver] = $this->seedSite();
        $updater = new PageUpdate(
            siteRepository: $siteRepository,
            pageRepository: $pageRepository,
            pathIndexer: new PathIndexer(
                siteRepository: $siteRepository,
                pageRepository: $pageRepository,
                sqlitePath: $this->projectRoot . '/runtime/index.sqlite',
            ),
            pathResolver: $pathResolver,
        );

        self::assertTrue($updater->slugExistsAmongSiblings('about-page', 'contact'));
        self::assertFalse($updater->slugExistsAmongSiblings('about-page', 'about'));
    }

    /**
     * @return array{0: SiteRepository, 1: PageRepository, 2: PathResolver}
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
                'text' => 'About body',
            ],
        ]);

        $pageRepository->save([
            'id' => 'contact-page',
            'parent_id' => 'home-page',
            'slug' => 'contact',
            'status' => 'listed',
            'sort' => 20,
            'fields' => [
                'title' => 'Contact',
            ],
        ]);

        (new PathIndexer(
            siteRepository: $siteRepository,
            pageRepository: $pageRepository,
            sqlitePath: $sqlitePath,
        ))->rebuild();

        return [
            $siteRepository,
            $pageRepository,
            new PathResolver(sqlitePath: $sqlitePath, pageRepository: $pageRepository),
        ];
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
