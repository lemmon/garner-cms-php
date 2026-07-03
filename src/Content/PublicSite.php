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

    /**
     * @param string $query Raw query string of the request (no "?"), preserved on
     *        canonical redirects. Controller-returned redirects are emitted verbatim.
     */
    public function respond(string $path, string $query = ''): RenderedResponse
    {
        $canonical = RoutePath::normalize($path);
        $page = $this->pages->find($canonical);

        // Trailing-slash (and extra leading-slash) spellings of a routable path
        // redirect permanently to the canonical form instead of serving the same
        // content at many URLs. Non-routable paths fall through to a plain 404 —
        // which also keeps drafts from being revealed through a redirect.
        if ($page !== null && $canonical !== $path) {
            return RenderedResponse::redirect($canonical . ($query === '' ? '' : '?' . $query));
        }

        $site = $this->siteLoader->load($this->pages);

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
