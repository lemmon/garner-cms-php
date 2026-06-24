<?php

declare(strict_types=1);

namespace Garner\Core;

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
            Response::content(
                body: $customResponse->body(),
                contentType: $customResponse->contentType(),
                status: $customResponse->status(),
            );
        }

        $this->renderPublicSite($path);
    }

    private function renderPublicSite(string $path): never
    {
        $response = $this->app->publicSite()->respond($path);

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
