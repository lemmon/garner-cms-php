<?php

declare(strict_types=1);

namespace Garner\Render;

use Garner\Content\Page;
use Garner\Content\Site;
use Garner\Core\Application;
use RuntimeException;

final class PageControllers
{
    /**
     * The site-wide controller, run for every rendered page. The name is
     * reserved: a template literally called "site" would resolve to the same
     * file and run it twice.
     */
    private const SITE_CONTROLLER = 'site.php';

    public function __construct(
        private readonly string $controllersPath,
    ) {}

    /**
     * Run the controllers for a page. The page's own controller may return a
     * RenderedResponse to bypass rendering entirely; otherwise its data is merged
     * over the site-wide controller's shared context (page keys win).
     *
     * @return array<string, mixed>|RenderedResponse
     */
    public function dispatch(Page $page, Site $site, Application $app): array|RenderedResponse
    {
        $result = $this->pageResult($page, $site, $app);

        if ($result instanceof RenderedResponse) {
            return $result;
        }

        return [...$this->siteResult($page, $site, $app), ...$result];
    }

    /**
     * @return array<string, mixed>|RenderedResponse
     */
    private function pageResult(Page $page, Site $site, Application $app): array|RenderedResponse
    {
        // A co-located +controller.php takes precedence over the template-based
        // site/controllers/{template}.php convention.
        $template = $page->template();
        $controllerFile =
            $page->controllerFile()
            ?? ($template !== null ? $this->templateController($template) : null);

        if ($controllerFile === null) {
            return [];
        }

        return $this->run($controllerFile, $page, $site, $app);
    }

    /**
     * Shared context from app/controllers/site.php. It provides data for the
     * template render, never a response, so a RenderedResponse is rejected.
     *
     * @return array<string, mixed>
     */
    private function siteResult(Page $page, Site $site, Application $app): array
    {
        $controllerFile = $this->controllersPath . '/' . self::SITE_CONTROLLER;

        if (!is_file($controllerFile)) {
            return [];
        }

        $result = $this->run($controllerFile, $page, $site, $app);

        if ($result instanceof RenderedResponse) {
            throw new RuntimeException(sprintf(
                'Site controller "%s" must return an array of shared context, not a response',
                $controllerFile,
            ));
        }

        return $result;
    }

    /**
     * @return array<string, mixed>|RenderedResponse
     */
    private function run(
        string $controllerFile,
        Page $page,
        Site $site,
        Application $app,
    ): array|RenderedResponse {
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

    private function templateController(string $template): ?string
    {
        $path = $this->controllersPath . '/' . $template . '.php';

        return is_file($path) ? $path : null;
    }
}
