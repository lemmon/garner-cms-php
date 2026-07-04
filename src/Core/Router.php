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
        $request = $this->app->request();
        $path = $request->path();

        if ($path === '/favicon.ico') {
            $this->renderFavicon();
        }

        $customResponse = $this->app->customRoutes()->respond($path, $this->app);
        if ($customResponse !== null) {
            $this->emit($customResponse);
        }

        $this->emit($this->app->publicSite()->respond(
            $path,
            $request->query(),
            $request->basePath(),
        ));
    }

    /**
     * Send a rendered response from any producer (custom routes, canonical-path
     * handling, and page/endpoint controllers alike). Redirects need no special
     * casing: the Location header rides the response verbatim — whoever built
     * the redirect owns its query.
     */
    private function emit(RenderedResponse $response): never
    {
        $response->send();
        exit();
    }

    private function renderFavicon(): never
    {
        $favicon = $this->app->favicon();

        $this->emit(new RenderedResponse(
            body: $favicon->content(),
            contentType: $favicon->contentType(),
        ));
    }
}
