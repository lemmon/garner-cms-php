<?php

declare(strict_types=1);

namespace Garner\Core;

use RuntimeException;

final class Router
{
    public function __construct(
        private readonly Application $app,
        private readonly string $backendPath,
    ) {}

    public function dispatch(): void
    {
        $path = Request::path();
        $apiPrefix = (string) $this->app->config('app.routes.api_prefix', '/api');
        $studioPrefix = (string) $this->app->config('app.routes.studio_prefix', '/studio');

        if ($path === '/favicon.ico') {
            $this->renderFavicon();
        }

        if (preg_match('#^' . preg_quote($studioPrefix, '#') . '(?:/.*)?$#', $path) === 1) {
            $this->renderStudio($path);
        }

        if (preg_match('#^' . preg_quote($apiPrefix, '#') . '/?(.*)$#', $path, $matches) === 1) {
            $action = trim($matches[1], '/');
            $action = $action === ''
                ? (string) $this->app->config('app.default_action', 'meta/health')
                : $action;

            $this->runAction($action);
        }

        $siteRouteResponse = $this->app->customRoutes()->respond($path, $this->app);
        if ($siteRouteResponse !== null) {
            Response::content(
                body: $siteRouteResponse->body(),
                contentType: $siteRouteResponse->contentType(),
                status: $siteRouteResponse->status(),
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

    private function renderStudio(string $path): never
    {
        $response = $this->app->studioApp()->respond($path);

        Response::content(
            body: $response->body(),
            contentType: $response->contentType(),
            status: $response->status(),
        );
    }

    private function runAction(string $action): never
    {
        if (!preg_match('/^[A-Za-z0-9_\/-]+$/', $action)) {
            Response::json([
                'error' => true,
                'message' => 'Invalid action name',
            ], 400);
        }

        $file = $this->backendPath . '/actions/' . $action . '.php';

        if (!is_file($file)) {
            Response::json([
                'error' => true,
                'message' => sprintf('Action "%s" not found', $action),
            ], 404);
        }

        $handler = require $file;

        if (!is_callable($handler)) {
            throw new RuntimeException(sprintf('Action "%s" must return a callable', $action));
        }

        $result = $handler($this->app);

        if (!is_array($result)) {
            throw new RuntimeException(sprintf('Action "%s" must return an array', $action));
        }

        Response::json($result);
    }
}
