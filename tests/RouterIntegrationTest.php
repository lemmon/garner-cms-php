<?php

declare(strict_types=1);

use Garner\Content\PageRepository;
use Garner\Content\SiteRepository;
use PHPUnit\Framework\TestCase;

final class RouterIntegrationTest extends TestCase
{
    private string $projectRoot;
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__);
        $this->projectRoot = sys_get_temp_dir() . '/garner-cms-router-' . bin2hex(random_bytes(6));

        mkdir($this->projectRoot . '/content/pages', 0o777, true);
        mkdir($this->projectRoot . '/frontend/build/_app/immutable/entry', 0o777, true);
        mkdir($this->projectRoot . '/site/blueprints/pages', 0o777, true);
        mkdir($this->projectRoot . '/site/blueprints/tabs', 0o777, true);
        mkdir($this->projectRoot . '/site/controllers', 0o777, true);
        mkdir($this->projectRoot . '/site/templates', 0o777, true);

        $this->writeStudioBuild();
        $this->writeBlueprint();
        $this->writeTemplate();
        $this->writeRoutes();
        $this->seedContent();
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->projectRoot);
    }

    public function testRouterLetsStudioAndApiWinOverCustomRoutes(): void
    {
        $studio = $this->dispatch('/studio');
        $studioAsset = $this->dispatch('/studio/_app/immutable/entry/studio.js');
        $studioRoute = $this->dispatch('/studio/users');
        $health = $this->dispatch('/api/meta/health');
        $contentStatus = $this->dispatch('/api/meta/content-status');
        $studioSite = $this->dispatch('/api/studio/site');
        $siteBlueprint = $this->dispatch('/api/studio/blueprints/site');
        $pageShow = $this->dispatch(
            '/api/studio/pages/show',
            'POST',
            '{"id":"about-page"}',
            'application/json',
        );
        $pageUpdate = $this->dispatch(
            '/api/studio/pages/update',
            'POST',
            '{"id":"about-page","title":"  Company  Name  ","slug":"Company Name!!"}',
            'application/json',
        );
        $pageFieldUpdate = $this->dispatch(
            '/api/studio/pages/update',
            'POST',
            '{"id":"about-page","text":"Updated about body"}',
            'application/json',
        );
        $siteUpdate = $this->dispatch(
            '/api/studio/site/update',
            'POST',
            '{"title":"Renamed Garner"}',
            'application/json',
        );

        self::assertSame('200', $studio['status']);
        self::assertStringContainsString('<title>Test Garner Studio</title>', $studio['body']);
        self::assertStringContainsString('/studio/_app/immutable/entry/studio.js', $studio['body']);
        self::assertStringNotContainsString('custom studio route', $studio['body']);

        self::assertSame('200', $studioAsset['status']);
        self::assertStringContainsString('console.log("studio asset");', $studioAsset['body']);

        self::assertSame('200', $studioRoute['status']);
        self::assertStringContainsString('<title>Test Garner Studio</title>', $studioRoute['body']);

        self::assertSame('200', $health['status']);
        self::assertStringContainsString('"ok": true', $health['body']);
        self::assertStringContainsString('"studio_prefix": "/studio"', $health['body']);
        self::assertStringNotContainsString('custom api route', $health['body']);

        self::assertSame('200', $contentStatus['status']);
        self::assertStringContainsString('"page_count": 4', $contentStatus['body']);
        self::assertStringContainsString('"/about"', $contentStatus['body']);

        self::assertSame('200', $studioSite['status']);
        self::assertStringContainsString('"title": "Test Garner"', $studioSite['body']);
        self::assertStringContainsString('"url": "https://test.garner.local"', $studioSite['body']);
        self::assertStringContainsString('"home_page_id": "home-page"', $studioSite['body']);

        self::assertSame('200', $siteBlueprint['status']);
        self::assertStringContainsString('"name": "site"', $siteBlueprint['body']);
        self::assertStringContainsString('"title": "Site"', $siteBlueprint['body']);
        self::assertStringContainsString('"type": "page_list"', $siteBlueprint['body']);

        self::assertSame('200', $pageShow['status']);
        self::assertStringContainsString('"id": "about-page"', $pageShow['body']);
        self::assertStringContainsString('"blueprint": "page"', $pageShow['body']);
        self::assertStringContainsString('"path": "/about"', $pageShow['body']);
        self::assertStringContainsString('"title": "Page"', $pageShow['body']);

        self::assertSame('200', $pageUpdate['status']);
        self::assertStringContainsString('"title": "Company Name"', $pageUpdate['body']);
        self::assertStringContainsString('"slug": "company-name"', $pageUpdate['body']);
        self::assertStringContainsString('"path": "/company-name"', $pageUpdate['body']);

        self::assertSame('200', $pageFieldUpdate['status']);
        self::assertStringContainsString('"id": "about-page"', $pageFieldUpdate['body']);
        self::assertStringContainsString('"text": "Updated about body"', $pageFieldUpdate['body']);
        self::assertStringContainsString('"path": "/company-name"', $pageFieldUpdate['body']);

        self::assertSame('200', $siteUpdate['status']);
        self::assertStringContainsString('"title": "Renamed Garner"', $siteUpdate['body']);
    }

    public function testRouterUsesValidationForSlugRulesOnPageUpdates(): void
    {
        $missingSlug = $this->dispatch(
            '/api/studio/pages/update',
            'POST',
            '{"id":"about-page","title":"Company","slug":"   "}',
            'application/json',
        );
        $duplicateSlug = $this->dispatch(
            '/api/studio/pages/update',
            'POST',
            '{"id":"about-page","title":"Company","slug":"Contact"}',
            'application/json',
        );

        self::assertSame('400', $missingSlug['status']);
        self::assertStringContainsString('"invalid": true', $missingSlug['body']);
        self::assertStringContainsString('"path": "slug"', $missingSlug['body']);
        self::assertStringContainsString('"message": "Value is required"', $missingSlug['body']);

        self::assertSame('400', $duplicateSlug['status']);
        self::assertStringContainsString('"invalid": true', $duplicateSlug['body']);
        self::assertStringContainsString('"path": "slug"', $duplicateSlug['body']);
        self::assertStringContainsString(
            '"message": "Slug must be unique among sibling pages"',
            $duplicateSlug['body'],
        );
    }

    public function testRouterKeepsReservedSlugValidationAheadOfCollidingBlueprintNodes(): void
    {
        $this->writePageBlueprint(<<<'YAML'
            title: Page
            tabs:
                - name: content
                  label: Content
                  nodes:
                      - type: text
                        name: slug
                        label: Slug
            YAML);

        $response = $this->dispatch(
            '/api/studio/pages/update',
            'POST',
            '{"id":"about-page","slug":"   "}',
            'application/json',
        );

        self::assertSame('400', $response['status']);
        self::assertStringContainsString('"invalid": true', $response['body']);
        self::assertStringContainsString('"path": "slug"', $response['body']);
        self::assertStringContainsString('"message": "Value is required"', $response['body']);
    }

    public function testRouterReturnsNotFoundForMissingOrUnknownPageUpdateIds(): void
    {
        $missingId = $this->dispatch(
            '/api/studio/pages/update',
            'POST',
            '{"title":"Company"}',
            'application/json',
        );
        $unknownId = $this->dispatch(
            '/api/studio/pages/update',
            'POST',
            '{"id":"missing-page","title":"Company"}',
            'application/json',
        );

        self::assertSame('404', $missingId['status']);
        self::assertStringContainsString('"error": true', $missingId['body']);
        self::assertStringContainsString('"message": "Page not found"', $missingId['body']);
        self::assertStringNotContainsString('"invalid": true', $missingId['body']);

        self::assertSame('404', $unknownId['status']);
        self::assertStringContainsString('"error": true', $unknownId['body']);
        self::assertStringContainsString('"message": "Page not found"', $unknownId['body']);
        self::assertStringNotContainsString('"invalid": true', $unknownId['body']);
    }

    public function testRouterReturnsNotFoundForMissingOrUnknownPageShowIds(): void
    {
        $missingId = $this->dispatch('/api/studio/pages/show', 'POST', '{}', 'application/json');
        $unknownId = $this->dispatch(
            '/api/studio/pages/show',
            'POST',
            '{"id":"missing-page"}',
            'application/json',
        );

        self::assertSame('404', $missingId['status']);
        self::assertStringContainsString('"error": true', $missingId['body']);
        self::assertStringContainsString('"message": "Page not found"', $missingId['body']);
        self::assertStringNotContainsString('"invalid": true', $missingId['body']);

        self::assertSame('404', $unknownId['status']);
        self::assertStringContainsString('"error": true', $unknownId['body']);
        self::assertStringContainsString('"message": "Page not found"', $unknownId['body']);
        self::assertStringNotContainsString('"invalid": true', $unknownId['body']);
    }

    public function testRouterReturnsNotFoundForMissingPageNodeSources(): void
    {
        $response = $this->dispatch(
            '/api/studio/nodes/query',
            'POST',
            '{"type":"page_list","source":"site.page(\"missing-page\")"}',
            'application/json',
        );

        self::assertSame('404', $response['status']);
        self::assertStringContainsString('"error": true', $response['body']);
        self::assertStringContainsString('"message": "Page not found"', $response['body']);
        self::assertStringNotContainsString('"invalid": true', $response['body']);
    }

    public function testRouterLetsCustomRoutesWinOverPublicPages(): void
    {
        $response = $this->dispatch('/example.txt');

        self::assertSame('200', $response['status']);
        self::assertStringContainsString('Custom example route', $response['body']);
        self::assertStringNotContainsString('<h1>Example Page</h1>', $response['body']);
    }

    public function testRouterCanStillRenderWhenOutputStartedBeforeResponse(): void
    {
        $response = $this->dispatch('/headers-sent');

        self::assertStringContainsString('debug-prefix', $response['body']);
        self::assertStringContainsString('<h1>Headers Sent</h1>', $response['body']);
    }

    public function testRouterReturnsJsonErrorsForInvalidAndMissingActions(): void
    {
        $invalid = $this->dispatch('/api/invalid!');
        $missing = $this->dispatch('/api/meta/missing');

        self::assertSame('400', $invalid['status']);
        self::assertStringContainsString('"message": "Invalid action name"', $invalid['body']);

        self::assertSame('404', $missing['status']);
        self::assertStringContainsString(
            '"message": "Action \"meta/missing\" not found"',
            $missing['body'],
        );
    }

    public function testRouterUsesGlobalErrorHandlerForInvalidJsonPayloads(): void
    {
        $response = $this->dispatch(
            path: '/api/studio/nodes/query',
            method: 'POST',
            body: '{"type":"page_list"',
            contentType: 'application/json',
        );

        self::assertSame('400', $response['status']);
        self::assertStringContainsString('"error": true', $response['body']);
        self::assertStringContainsString('"message": "Syntax error"', $response['body']);
    }

    public function testRouterUsesValidatorForStudioNodeQueryPayloads(): void
    {
        $response = $this->dispatch(
            path: '/api/studio/nodes/query',
            method: 'POST',
            body: '{"type":"page_list"}',
            contentType: 'application/json',
        );

        self::assertSame('400', $response['status']);
        self::assertStringContainsString('"invalid": true', $response['body']);
        self::assertStringContainsString('"path": "source"', $response['body']);
    }

    public function testRouterReturnsErrorResponsesForStudioServiceFailures(): void
    {
        $response = $this->dispatch(
            path: '/api/studio/nodes/query',
            method: 'POST',
            body: json_encode([
                'type' => 'page_list',
                'source' => 'site',
                'query' => 'source.unsupported()',
            ], JSON_THROW_ON_ERROR),
            contentType: 'application/json',
        );

        self::assertSame('400', $response['status']);
        self::assertStringContainsString('"error": true', $response['body']);
        self::assertStringNotContainsString('"invalid": true', $response['body']);
        self::assertStringContainsString('Unsupported page list query', $response['body']);
    }

    /**
     * @return array{body: string, status: string}
     */
    private function dispatch(
        string $path,
        string $method = 'GET',
        string $body = '',
        string $contentType = 'application/json',
    ): array {
        $runner = $this->projectRoot . '/router-runner.php';
        $statusFile = $this->projectRoot . '/router-status.txt';

        file_put_contents($runner, $this->runnerScript(
            $path,
            $statusFile,
            $method,
            $contentType,
            $body,
        ));

        $process = proc_open(
            [PHP_BINARY, $runner],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        if (!is_resource($process)) {
            self::fail('Failed to start router runner process');
        }

        fwrite($pipes[0], $body);
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            self::fail('Router runner failed with exit code ' . $exitCode . ': ' . $stderr);
        }

        $status = file_get_contents($statusFile);

        return [
            'body' => is_string($stdout) ? $stdout : '',
            'status' => is_string($status) ? $status : '',
        ];
    }

    private function runnerScript(
        string $path,
        string $statusFile,
        string $method,
        string $contentType,
        string $body,
    ): string {
        $autoload = var_export($this->repoRoot . '/vendor/autoload.php', true);
        $corePath = var_export($this->repoRoot, true);
        $projectRoot = var_export($this->projectRoot, true);
        $requestPath = var_export($path, true);
        $statusPath = var_export($statusFile, true);
        $requestMethod = var_export($method, true);
        $requestContentType = var_export($contentType, true);
        $requestContentLength = var_export(strlen($body), true);
        $config = var_export([
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
                'studio' => [
                    'build_path' => $this->projectRoot . '/frontend/build',
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
        ], true);

        return <<<PHP
            <?php

            declare(strict_types=1);

            require {$autoload};

            \$_SERVER['REQUEST_URI'] = {$requestPath};
            \$_SERVER['REQUEST_METHOD'] = {$requestMethod};
            \$_SERVER['CONTENT_TYPE'] = {$requestContentType};
            \$_SERVER['CONTENT_LENGTH'] = {$requestContentLength};
            \$_SERVER['HTTP_HOST'] = 'test.garner.local';
            \$_SERVER['REQUEST_SCHEME'] = 'https';

            register_shutdown_function(static function (): void {
                file_put_contents({$statusPath}, (string) http_response_code());
            });

            \$app = new \Garner\Core\Application(
                corePath: {$corePath},
                projectRootPath: {$projectRoot},
                config: {$config},
            );

            \$app->run();
            PHP;
    }

    private function seedContent(): void
    {
        $site = new SiteRepository($this->projectRoot . '/content');
        $pages = new PageRepository($this->projectRoot . '/content');

        $site->save([
            'home_page_id' => 'home-page',
            'title' => 'Test Garner',
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
                'text' => 'About body',
            ],
        ]);

        $pages->save([
            'id' => 'example-page',
            'parent_id' => 'home-page',
            'slug' => 'example.txt',
            'status' => 'listed',
            'sort' => 20,
            'template' => 'default',
            'fields' => [
                'title' => 'Example Page',
            ],
        ]);

        $pages->save([
            'id' => 'contact-page',
            'parent_id' => 'home-page',
            'slug' => 'contact',
            'status' => 'listed',
            'sort' => 30,
            'template' => 'default',
            'fields' => [
                'title' => 'Contact',
            ],
        ]);
    }

    private function writeTemplate(): void
    {
        file_put_contents($this->projectRoot . '/site/templates/default.twig', <<<'TWIG'
            <!doctype html>
            <html lang="en">
              <head>
                <meta charset="utf-8">
                <title>{{ page.title }} | {{ site.title }}</title>
              </head>
              <body>
                <main>
                  <h1>{{ page.title }}</h1>
                </main>
              </body>
            </html>
            TWIG);
    }

    private function writeBlueprint(): void
    {
        file_put_contents($this->projectRoot . '/site/blueprints/site.yml', <<<'YAML'
            title: Site

            tabs:
                - name: pages
                  label: Pages
                  nodes:
                      - type: page_list
                        name: pages
                        label: Pages
                        source: site
                        create:
                            enabled: true

                - extends: tabs/files
            YAML);

        file_put_contents($this->projectRoot . '/site/blueprints/tabs/files.yml', <<<'YAML'
            name: files
            label: Files
            nodes:
                - type: file_list
                  name: files
                  label: Files
                  source: site
                  upload:
                      enabled: true
            YAML);

        $this->writePageBlueprint(<<<'YAML'
            title: Page
            tabs:
                - name: content
                  label: Content
                  nodes:
                      - type: textarea
                        name: text
                        label: Text
            YAML);
    }

    private function writePageBlueprint(string $yaml): void
    {
        file_put_contents($this->projectRoot . '/site/blueprints/pages/page.yml', $yaml);
    }

    private function writeStudioBuild(): void
    {
        file_put_contents($this->projectRoot . '/frontend/build/index.html', <<<'HTML'
            <!doctype html>
            <html lang="en">
              <head>
                <meta charset="utf-8">
                <title>Test Garner Studio</title>
                <script type="module" src="/studio/_app/immutable/entry/studio.js"></script>
              </head>
              <body>
                <div id="app"></div>
              </body>
            </html>
            HTML);

        file_put_contents(
            $this->projectRoot . '/frontend/build/_app/immutable/entry/studio.js',
            'console.log("studio asset");',
        );
    }

    private function writeRoutes(): void
    {
        file_put_contents($this->projectRoot . '/site/routes.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Garner\Core\Application;
            use Garner\Site\RenderedResponse;

            return [
                '/api/meta/health' => static fn(Application $app): RenderedResponse => RenderedResponse::text('custom api route'),
                '/example.txt' => static fn(Application $app): RenderedResponse => RenderedResponse::text('Custom example route'),
                '/headers-sent' => static function (Application $app): RenderedResponse {
                    echo 'debug-prefix';

                    return RenderedResponse::html('<h1>Headers Sent</h1>');
                },
                '/studio' => static fn(Application $app): RenderedResponse => RenderedResponse::text('custom studio route'),
            ];
            PHP);
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
