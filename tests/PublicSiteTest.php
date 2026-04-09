<?php

declare(strict_types=1);

use Garner\Content\PageRepository;
use Garner\Content\PathIndexer;
use Garner\Content\PathResolver;
use Garner\Content\SiteRepository;
use Garner\Core\Application;
use Garner\Site\PageControllers;
use Garner\Site\PublicSite;
use Garner\Site\RendererInterface;
use Garner\Site\TwigRenderer;
use PHPUnit\Framework\TestCase;

final class PublicSiteTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot =
            sys_get_temp_dir() . '/garner-cms-public-site-' . bin2hex(random_bytes(6));

        mkdir($this->projectRoot . '/content/pages', 0o777, true);
        mkdir($this->projectRoot . '/site/controllers', 0o777, true);
        mkdir($this->projectRoot . '/site/templates', 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->projectRoot);
    }

    public function testPublicSiteRendersPagesAndFallsBackToDefaultTemplate(): void
    {
        $this->writeTemplates();
        $this->writeControllers();

        $siteRepository = new SiteRepository($this->projectRoot . '/content');
        $pageRepository = new PageRepository($this->projectRoot . '/content');

        $siteRepository->save([
            'home_page_id' => 'home-page',
            'title' => 'Test Site',
        ]);

        $pageRepository->save([
            'id' => 'home-page',
            'slug' => 'home',
            'status' => 'listed',
            'template' => 'default',
            'fields' => [
                'title' => 'Home',
                'text' => 'Welcome home.',
            ],
        ]);

        $pageRepository->save([
            'id' => 'about-page',
            'parent_id' => 'home-page',
            'slug' => 'about',
            'status' => 'listed',
            'template' => 'article',
            'fields' => [
                'title' => 'About',
                'text' => 'About page body.',
            ],
        ]);

        $pageRepository->save([
            'id' => 'controller-page',
            'parent_id' => 'home-page',
            'slug' => 'controller-data',
            'status' => 'listed',
            'template' => 'controller-data',
            'fields' => [
                'title' => 'Controller Data',
            ],
        ]);

        $pageRepository->save([
            'id' => 'markdown-page',
            'parent_id' => 'home-page',
            'slug' => 'markdown',
            'status' => 'listed',
            'template' => 'default',
            'fields' => [
                'title' => 'Markdown Example',
                'text' => "**Bold**\n\n- One\n- Two\n\n[About](/about){class=\"inline-link\"}",
            ],
        ]);

        $publicSite = $this->makePublicSite(
            siteRepository: $siteRepository,
            pageRepository: $pageRepository,
            renderer: new TwigRenderer($this->projectRoot . '/site/templates'),
        );

        $homeResponse = $publicSite->respond('/');
        $aboutResponse = $publicSite->respond('/about');
        $controllerResponse = $publicSite->respond('/controller-data');
        $markdownResponse = $publicSite->respond('/markdown');

        self::assertSame(200, $homeResponse->status());
        self::assertStringContainsString('<h1>Home</h1>', $homeResponse->body());
        self::assertStringContainsString('Welcome home.', $homeResponse->body());

        self::assertSame(200, $aboutResponse->status());
        self::assertStringContainsString('<h1>About</h1>', $aboutResponse->body());
        self::assertStringContainsString('About page body.', $aboutResponse->body());
        self::assertStringContainsString('<a href="/about">About</a>', $aboutResponse->body());

        self::assertSame(200, $controllerResponse->status());
        self::assertStringContainsString('<h1>Controller Data</h1>', $controllerResponse->body());
        self::assertStringContainsString(
            'Test Site says: Controller Data is controller-backed.',
            $controllerResponse->body(),
        );

        self::assertSame(200, $markdownResponse->status());
        self::assertStringContainsString('<h1>Markdown Example</h1>', $markdownResponse->body());
        self::assertStringContainsString('<strong>Bold</strong>', $markdownResponse->body());
        self::assertStringContainsString('<li>One</li>', $markdownResponse->body());
        self::assertStringContainsString('class="inline-link"', $markdownResponse->body());
    }

    public function testPublicSiteRendersNotFoundResponses(): void
    {
        $this->writeTemplates();
        $this->writeControllers();

        $siteRepository = new SiteRepository($this->projectRoot . '/content');
        $pageRepository = new PageRepository($this->projectRoot . '/content');

        $siteRepository->save([
            'home_page_id' => 'home-page',
            'title' => 'Test Site',
        ]);

        $pageRepository->save([
            'id' => 'home-page',
            'slug' => 'home',
            'status' => 'listed',
            'fields' => [
                'title' => 'Home',
            ],
        ]);

        $webSite = $this->makePublicSite(
            siteRepository: $siteRepository,
            pageRepository: $pageRepository,
            renderer: new TwigRenderer($this->projectRoot . '/site/templates'),
        );
        $missingResponse = $webSite->respond('/missing');

        self::assertSame(404, $missingResponse->status());
        self::assertStringContainsString('No page matched', $missingResponse->body());
        self::assertStringContainsString('/missing', $missingResponse->body());
    }

    public function testControllerCanShortCircuitTemplateWithCustomResponse(): void
    {
        $this->writeTemplates();
        $this->writeControllers();

        $siteRepository = new SiteRepository($this->projectRoot . '/content');
        $pageRepository = new PageRepository($this->projectRoot . '/content');

        $siteRepository->save([
            'home_page_id' => 'home-page',
            'title' => 'Test Site',
        ]);

        $pageRepository->save([
            'id' => 'home-page',
            'slug' => 'home',
            'status' => 'listed',
            'fields' => [
                'title' => 'Home',
            ],
        ]);

        $pageRepository->save([
            'id' => 'controller-response-page',
            'parent_id' => 'home-page',
            'slug' => 'controller-response',
            'status' => 'listed',
            'template' => 'controller-response',
            'fields' => [
                'title' => 'Controller Response',
            ],
        ]);

        $publicSite = $this->makePublicSite(
            siteRepository: $siteRepository,
            pageRepository: $pageRepository,
            renderer: new TwigRenderer($this->projectRoot . '/site/templates'),
        );

        $response = $publicSite->respond('/controller-response');

        self::assertSame(200, $response->status());
        self::assertSame('text/plain; charset=utf-8', $response->contentType());
        self::assertSame("Controller response for Controller Response.\n", $response->body());
    }

    private function makePublicSite(
        SiteRepository $siteRepository,
        PageRepository $pageRepository,
        RendererInterface $renderer,
    ): PublicSite {
        $app = new Application(
            corePath: dirname(__DIR__),
            projectRootPath: $this->projectRoot,
            config: [
                'app' => [
                    'name' => 'Test Site',
                    'paths' => [
                        'content' => 'content',
                        'runtime' => 'runtime',
                        'site' => 'site',
                        'storage' => 'storage',
                    ],
                    'rendering' => [
                        'default_template' => 'default',
                    ],
                ],
            ],
        );

        $pathResolver = new PathResolver(
            sqlitePath: $this->projectRoot . '/runtime/index.sqlite',
            pageRepository: $pageRepository,
        );

        return new PublicSite(
            app: $app,
            siteRepository: $siteRepository,
            pageRepository: $pageRepository,
            pathIndexer: new PathIndexer(
                siteRepository: $siteRepository,
                pageRepository: $pageRepository,
                sqlitePath: $this->projectRoot . '/runtime/index.sqlite',
            ),
            pathResolver: $pathResolver,
            pageControllers: new PageControllers($this->projectRoot . '/site/controllers'),
            renderer: $renderer,
            indexPath: $this->projectRoot . '/runtime/index.sqlite',
        );
    }

    private function writeTemplates(): void
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
                  <nav>
                    {% for item in pages.listed() %}
                      <a href="{{ item.url }}">{{ item.title }}</a>
                    {% endfor %}
                  </nav>
                  <h1>{{ page.title }}</h1>
                  {% set text = page.value('text') %}
                  {% if text %}
                    {{ text|markdown }}
                  {% endif %}
                </main>
              </body>
            </html>
            TWIG);

        file_put_contents($this->projectRoot . '/site/templates/error.twig', <<<'TWIG'
            <!doctype html>
            <html lang="en">
              <head>
                <meta charset="utf-8">
                {% set error_title = page is defined ? page.title : (error.title ?? 'Application Error') %}
                <title>{{ error_title }} | {{ site.title }}</title>
              </head>
              <body>
                <main>
                  <h1>{{ error_title }}</h1>
                  {% if error.kind == 'not_found' %}
                    <p>No page matched <code>{{ error.path }}</code>.</p>
                  {% else %}
                    <p>The request could not be completed.</p>
                  {% endif %}
                </main>
              </body>
            </html>
            TWIG);

        file_put_contents($this->projectRoot . '/site/templates/controller-data.twig', <<<'TWIG'
            <!doctype html>
            <html lang="en">
              <head>
                <meta charset="utf-8">
                <title>{{ page.title }} | {{ site.title }}</title>
              </head>
              <body>
                <main>
                  <h1>{{ page.title }}</h1>
                  <p>{{ controller_message }}</p>
                </main>
              </body>
            </html>
            TWIG);
    }

    private function writeControllers(): void
    {
        file_put_contents($this->projectRoot . '/site/controllers/controller-data.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Garner\Core\Application;
            use Garner\Site\Page;
            use Garner\Site\Pages;
            use Garner\Site\Site;

            return static function (Page $page, Site $site, Pages $pages, Application $app): array {
                return [
                    'controller_message' => sprintf('%s says: %s is controller-backed.', (string) $app->config('app.name', 'Garner CMS'), $page->title()),
                ];
            };
            PHP);

        file_put_contents($this->projectRoot . '/site/controllers/controller-response.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Garner\Core\Application;
            use Garner\Site\Page;
            use Garner\Site\Pages;
            use Garner\Site\RenderedResponse;
            use Garner\Site\Site;

            return static function (Page $page, Site $site, Pages $pages, Application $app): RenderedResponse {
                return RenderedResponse::text(sprintf("Controller response for %s.\n", $page->title()));
            };
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
