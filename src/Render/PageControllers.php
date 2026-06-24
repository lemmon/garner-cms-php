<?php

declare(strict_types=1);

namespace Garner\Render;

use Garner\Content\Page;
use Garner\Content\Site;
use Garner\Core\Application;
use RuntimeException;

final class PageControllers
{
    public function __construct(
        private readonly string $controllersPath,
    ) {}

    /**
     * @return array<string, mixed>|RenderedResponse
     */
    public function dispatch(Page $page, Site $site, Application $app): array|RenderedResponse
    {
        // A co-located +controller.php takes precedence over the template-based
        // site/controllers/{template}.php convention.
        $template = $page->template();
        $controllerFile =
            $page->controllerFile()
            ?? ($template !== null ? $this->siteController($template) : null);

        if ($controllerFile === null) {
            return [];
        }

        $controller = require $controllerFile;

        if (!is_callable($controller)) {
            throw new RuntimeException(sprintf(
                'Controller "%s" must return a callable',
                $controllerFile,
            ));
        }

        $result = $controller($page, $site, $app);

        if ($result instanceof RenderedResponse) {
            return $result;
        }

        if (!is_array($result)) {
            throw new RuntimeException(sprintf(
                'Controller "%s" must return an array or RenderedResponse',
                $controllerFile,
            ));
        }

        return $result;
    }

    private function siteController(string $template): ?string
    {
        $path = $this->controllersPath . '/' . $template . '.php';

        return is_file($path) ? $path : null;
    }
}
