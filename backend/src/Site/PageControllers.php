<?php

declare(strict_types=1);

namespace Garner\Site;

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
    public function dispatch(
        Page $page,
        Site $site,
        Pages $pages,
        Application $app,
    ): array|RenderedResponse {
        $controllerFile = $this->controllersPath . '/' . $page->template() . '.php';

        if (!is_file($controllerFile)) {
            return [];
        }

        $controller = require $controllerFile;

        if (!is_callable($controller)) {
            throw new RuntimeException(sprintf(
                'Controller "%s" must return a callable',
                $controllerFile,
            ));
        }

        $result = $controller($page, $site, $pages, $app);

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
}
