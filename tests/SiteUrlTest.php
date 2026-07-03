<?php

declare(strict_types=1);

namespace Garner\Tests;

use Garner\Core\Application;
use Garner\Core\Request;
use PHPUnit\Framework\TestCase;

final class SiteUrlTest extends TestCase
{
    /**
     * @var array<string, mixed>
     */
    private array $server;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
    }

    public function testBaseUrlInfersSchemeAndHostFromRequest(): void
    {
        unset($_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['HTTPS'], $_SERVER['REQUEST_SCHEME']);
        $_SERVER['HTTP_HOST'] = 'example.test:8080';

        self::assertSame('http://example.test:8080', Request::baseUrl());

        $_SERVER['HTTPS'] = 'on';
        self::assertSame('https://example.test:8080', Request::baseUrl());
    }

    public function testBaseUrlFallsBackToLocalhostWithoutHost(): void
    {
        unset(
            $_SERVER['HTTP_HOST'],
            $_SERVER['SERVER_NAME'],
            $_SERVER['HTTPS'],
            $_SERVER['HTTP_X_FORWARDED_PROTO'],
            $_SERVER['REQUEST_SCHEME'],
        );

        self::assertSame('http://localhost', Request::baseUrl());
    }

    public function testSiteUrlPrefersConfigOverrideAndStripsTrailingSlash(): void
    {
        $app = new Application('/tmp', '/tmp', [
            'app' => ['url' => 'https://www.php-git-deploy.com/'],
        ]);

        self::assertSame('https://www.php-git-deploy.com', $app->siteUrl());
    }

    public function testSiteUrlFallsBackToRequestWhenConfigAbsent(): void
    {
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['REQUEST_SCHEME']);
        $_SERVER['HTTP_HOST'] = 'localhost:8000';

        $app = new Application('/tmp', '/tmp', ['app' => []]);

        self::assertSame('http://localhost:8000', $app->siteUrl());
    }

    public function testSiteModelExposesResolvedUrl(): void
    {
        $app = new Application('/tmp', '/tmp', [
            'app' => ['url' => 'https://example.com'],
        ]);

        self::assertSame('https://example.com', $app->siteLoader()->load()->url());
    }

    public function testPageUrlIsAbsoluteWhilePathStaysTheRoute(): void
    {
        $root = sys_get_temp_dir() . '/garner-page-url-' . bin2hex(random_bytes(6));
        mkdir($root . '/routes/about', 0o777, true);
        file_put_contents($root . '/routes/+page.json', '{"title": "Home"}');
        file_put_contents($root . '/routes/about/+page.json', '{"title": "About"}');

        try {
            $app = new Application($root, $root, [
                'app' => ['debug' => true, 'url' => 'https://example.com'],
            ]);

            $home = $app->pages()->home();
            $about = $app->pages()->find('/about');

            self::assertNotNull($home);
            self::assertNotNull($about);
            self::assertSame('/', $home->path());
            self::assertSame('https://example.com/', $home->url());
            self::assertSame('/about', $about->path());
            self::assertSame('https://example.com/about', $about->url());
        } finally {
            $this->removeDirectory($root);
        }
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
