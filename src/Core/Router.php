<?php

declare(strict_types=1);

namespace Garner\Core;

use Garner\Render\RenderedResponse;

final class Router
{
    public function __construct(
        private readonly Application $app,
    ) {}

    public function dispatch(): never
    {
        $path = Request::path();

        if ($path === '/favicon.ico') {
            $this->renderFavicon();
        }

        $customResponse = $this->app->customRoutes()->respond($path, $this->app);
        if ($customResponse !== null) {
            $this->emit($customResponse);
        }

        $this->emit($this->app->publicSite()->respond($path, Request::query()));
    }

    /**
     * Send a rendered response, honoring redirects from every producer (custom
     * routes, canonical-path handling, and page/endpoint controllers alike). The
     * Location is emitted verbatim — whoever built the redirect owns its query.
     */
    private function emit(RenderedResponse $response): never
    {
        $location = $response->location();

        if ($location !== null) {
            Response::redirect($location, $response->status());
        }

        Response::content(
            body: $response->body(),
            contentType: $response->contentType(),
            status: $response->status(),
        );
    }

    private function renderFavicon(): never
    {
        $favicon = $this->app->favicon();

        Response::content(body: $favicon->content(), contentType: $favicon->contentType());
    }
}
