<?php

declare(strict_types=1);

namespace Garner\Content;

use Garner\Core\Application;
use Garner\Render\PageControllers;
use Garner\Render\RenderedResponse;
use Garner\Render\RendererInterface;

final class PublicSite
{
    public function __construct(
        private readonly Application $app,
        private readonly Pages $pages,
        private readonly SiteLoader $siteLoader,
        private readonly PageControllers $controllers,
        private readonly RendererInterface $renderer,
    ) {}

    public function respond(string $path): RenderedResponse
    {
        $site = $this->siteLoader->load($this->pages);
        $page = $this->pages->find($path);

        if ($page === null) {
            return RenderedResponse::html($this->renderer->renderNotFound($site, $path), 404);
        }

        $result = $this->controllers->dispatch($page, $site, $this->app);

        if ($result instanceof RenderedResponse) {
            return $result;
        }

        return RenderedResponse::html($this->renderer->renderPage($page, $site, $result));
    }
}
