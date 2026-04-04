<?php

declare(strict_types=1);

use Garner\Content\PageRepository;
use Garner\Content\SiteRepository;
use Garner\Core\Application;
use PHPUnit\Framework\TestCase;

final class StudioBootstrapActionTest extends TestCase
{
    private string $projectRoot;
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__);
        $this->projectRoot =
            sys_get_temp_dir() . '/garner-cms-studio-bootstrap-' . bin2hex(random_bytes(6));

        mkdir($this->projectRoot . '/content/pages', 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->projectRoot);
    }

    public function testStudioBootstrapReturnsSiteStatsAndPages(): void
    {
        $site = new SiteRepository($this->projectRoot . '/content');
        $pages = new PageRepository($this->projectRoot . '/content');

        $site->save([
            'title' => 'Test Garner',
            'home_page_id' => 'home-page',
        ]);

        $pages->save([
            'id' => 'home-page',
            'slug' => 'home',
            'status' => 'listed',
            'sort' => 1,
            'template' => 'default',
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
            'template' => 'default',
            'fields' => [
                'title' => 'About',
            ],
        ]);

        $pages->save([
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

        $handler = require $this->repoRoot . '/backend/actions/studio/bootstrap.php';
        $payload = $handler($this->makeApplication());

        self::assertTrue($payload['ok']);
        self::assertSame('Test Garner', $payload['site']['title']);
        self::assertSame('home-page', $payload['site']['home_page_id']);
        self::assertSame(3, $payload['stats']['page_count']);
        self::assertSame(2, $payload['stats']['listed_count']);
        self::assertSame(1, $payload['stats']['draft_count']);

        $indexedPages = [];

        foreach ($payload['pages'] as $page) {
            $indexedPages[$page['id']] = $page;
        }

        self::assertSame('/', $indexedPages['home-page']['path']);
        self::assertTrue($indexedPages['home-page']['is_home']);
        self::assertSame('/about', $indexedPages['about-page']['path']);
        self::assertNull($indexedPages['draft-page']['path']);
    }

    private function makeApplication(): Application
    {
        return new Application(
            backendPath: $this->repoRoot . '/backend',
            rootPath: $this->projectRoot,
            config: [
                'app' => [
                    'name' => 'Test Garner',
                    'default_action' => 'meta/health',
                    'paths' => [
                        'content' => 'content',
                        'runtime' => 'runtime',
                        'site' => 'site',
                        'storage' => 'storage',
                    ],
                    'routes' => [
                        'api_prefix' => '/api',
                        'studio_prefix' => '/studio',
                    ],
                    'rendering' => [
                        'default_template' => 'default',
                        'engine' => 'twig',
                    ],
                    'markdown' => [
                        'allow_unsafe_links' => false,
                        'html_input' => 'strip',
                    ],
                ],
            ],
        );
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
