<?php

declare(strict_types=1);

use Garner\Content\SiteRepository;
use Garner\Core\Application;
use PHPUnit\Framework\TestCase;

final class StudioSiteActionTest extends TestCase
{
    private string $projectRoot;
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__);
        $this->projectRoot =
            sys_get_temp_dir() . '/garner-cms-studio-site-' . bin2hex(random_bytes(6));

        mkdir($this->projectRoot . '/content', 0o777, true);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_HOST'], $_SERVER['REQUEST_SCHEME']);

        $this->deleteDirectory($this->projectRoot);
    }

    public function testStudioSiteReturnsMinimalSiteData(): void
    {
        $site = new SiteRepository($this->projectRoot . '/content');

        $site->save([
            'title' => 'Test Garner',
            'error_page_id' => 'error-page',
            'home_page_id' => 'home-page',
        ]);

        $_SERVER['HTTP_HOST'] = 'test.garner.local';
        $_SERVER['REQUEST_SCHEME'] = 'https';

        $handler = require $this->repoRoot . '/backend/actions/studio/site.php';
        $payload = $handler($this->makeApplication());

        self::assertTrue($payload['ok']);
        self::assertSame('site', $payload['site']['id']);
        self::assertSame('Test Garner', $payload['site']['title']);
        self::assertSame('https://test.garner.local', $payload['site']['url']);
        self::assertSame('error-page', $payload['site']['error_page_id']);
        self::assertSame('home-page', $payload['site']['home_page_id']);
    }

    private function makeApplication(): Application
    {
        return new Application(
            corePath: $this->repoRoot,
            projectRootPath: $this->projectRoot,
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
