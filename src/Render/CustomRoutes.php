<?php

declare(strict_types=1);

namespace Garner\Render;

use Garner\Core\Application;
use RuntimeException;

final class CustomRoutes
{
    public function __construct(
        private readonly string $routesFile,
    ) {}

    public function respond(string $path, Application $app): ?RenderedResponse
    {
        if (!is_file($this->routesFile)) {
            return null;
        }

        $routes = require $this->routesFile;

        if (!is_array($routes)) {
            throw new RuntimeException(sprintf(
                'Routes file "%s" must return an array',
                $this->routesFile,
            ));
        }

        $handler = $routes[$path] ?? null;

        if ($handler === null) {
            return null;
        }

        if (!is_callable($handler)) {
            throw new RuntimeException(sprintf('Route "%s" must be callable', $path));
        }

        $response = $handler($app);

        if (!$response instanceof RenderedResponse) {
            throw new RuntimeException(sprintf('Route "%s" must return a RenderedResponse', $path));
        }

        return $response;
    }
}
