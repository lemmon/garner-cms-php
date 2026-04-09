<?php

declare(strict_types=1);

use Garner\Core\Application;
use Garner\Site\CustomRoutes;
use Garner\Site\RenderedResponse;
use PHPUnit\Framework\TestCase;

final class CustomRoutesTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot =
            sys_get_temp_dir() . '/garner-cms-custom-routes-' . bin2hex(random_bytes(6));
        mkdir($this->projectRoot . '/site', 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->projectRoot);
    }

    public function testCustomRoutesCanReturnTextAndJsonResponses(): void
    {
        file_put_contents($this->projectRoot . '/site/routes.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Garner\Core\Application;
            use Garner\Site\RenderedResponse;

            return [
                '/hello.txt' => static fn (Application $app): RenderedResponse => RenderedResponse::text(
                    sprintf('%s says hello', (string) $app->config('app.name', 'Garner CMS')),
                ),
                '/status.json' => static fn (Application $app): RenderedResponse => RenderedResponse::json([
                    'name' => (string) $app->config('app.name', 'Garner CMS'),
                    'ok' => true,
                ]),
            ];
            PHP);

        $routes = new CustomRoutes($this->projectRoot . '/site/routes.php');
        $app = new Application(
            corePath: dirname(__DIR__),
            projectRootPath: $this->projectRoot,
            config: [
                'app' => [
                    'name' => 'Test Garner',
                ],
            ],
        );

        $textResponse = $routes->respond('/hello.txt', $app);
        $jsonResponse = $routes->respond('/status.json', $app);

        self::assertInstanceOf(RenderedResponse::class, $textResponse);
        self::assertSame('text/plain; charset=utf-8', $textResponse->contentType());
        self::assertSame('Test Garner says hello', $textResponse->body());

        self::assertInstanceOf(RenderedResponse::class, $jsonResponse);
        self::assertSame('application/json; charset=utf-8', $jsonResponse->contentType());
        self::assertStringContainsString('"ok": true', $jsonResponse->body());
        self::assertNull($routes->respond('/missing', $app));
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
