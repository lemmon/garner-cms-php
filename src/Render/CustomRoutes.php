<?php

declare(strict_types=1);

namespace Garner\Render;

use Closure;
use Garner\Content\RoutePath;
use Garner\Content\Site;
use Garner\Core\Application;
use Garner\Core\NotFoundException;
use Garner\Support\PhpFile;
use ReflectionFunction;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use RuntimeException;

/**
 * Routes registered in app/routes.php: an array of exact path => handler,
 * dispatched ahead of filesystem routing for any HTTP method. A handler is
 * called as ($app) or ($app, $site); the Site is only loaded for handlers that
 * declare the second parameter, so a JSON or webhook route never pays for it.
 */
final class CustomRoutes
{
    public function __construct(
        private readonly string $routesFile,
    ) {}

    /**
     * @param string $query Raw query string of the request (no "?"), kept on
     *        the trailing-slash redirect.
     * @param string $basePath Front-controller base path stripped from $path,
     *        re-attached to the trailing-slash redirect.
     */
    public function respond(
        string $path,
        Application $app,
        string $query = '',
        string $basePath = '',
    ): ?RenderedResponse {
        if (!is_file($this->routesFile)) {
            return null;
        }

        $routes = PhpFile::load($this->routesFile);

        if (!is_array($routes)) {
            throw new RuntimeException(sprintf(
                'Routes file "%s" must return an array',
                $this->routesFile,
            ));
        }

        $handler = $routes[$path] ?? null;

        if ($handler === null) {
            // Same canonicalization as filesystem pages: a trailing-slash (or
            // extra leading-slash) spelling of a registered route redirects
            // permanently to the registered path.
            $canonical = RoutePath::normalize($path);

            if ($canonical !== $path && ($routes[$canonical] ?? null) !== null) {
                return RenderedResponse::redirect(
                    $basePath . $canonical . ($query === '' ? '' : '?' . $query),
                );
            }

            return null;
        }

        if (!is_callable($handler)) {
            throw new RuntimeException(sprintf('Route "%s" must be callable', $path));
        }

        $handler = Closure::fromCallable($handler);
        $site = self::wantsSite($handler, $path) ? $app->siteLoader()->load($app->pages()) : null;

        try {
            $response = $site instanceof Site ? $handler($app, $site) : $handler($app);
        } catch (NotFoundException) {
            return $app->publicSite()->notFound($path, $site);
        }

        if (!$response instanceof RenderedResponse) {
            throw new RuntimeException(sprintf('Route "%s" must return a RenderedResponse', $path));
        }

        return $response;
    }

    /**
     * Whether the handler takes the Site: it declares a second parameter, or a
     * variadic one that would collect it. That parameter must accept a Site
     * (untyped, `Site`, `object`, `mixed`, or a union including one of them);
     * any other type is a contract error reported here, not a TypeError from
     * the call.
     */
    private static function wantsSite(Closure $handler, string $path): bool
    {
        $parameters = new ReflectionFunction($handler)->getParameters();
        // Without a second parameter, the only one left that can collect the
        // Site is a variadic first parameter (`fn(...$args)`).
        $first = $parameters[0] ?? null;
        $target = $parameters[1] ?? ($first?->isVariadic() === true ? $first : null);

        if ($target === null) {
            return false;
        }

        $type = $target->getType();

        if ($type === null || self::acceptsSite($type)) {
            return true;
        }

        throw new RuntimeException(sprintf(
            'Route "%s" handler parameter $%s is typed %s, but receives the %s: '
            . 'handlers are called as ($app) or ($app, $site)',
            $path,
            $target->getName(),
            (string) $type,
            Site::class,
        ));
    }

    private static function acceptsSite(ReflectionType $type): bool
    {
        if ($type instanceof ReflectionNamedType) {
            return (
                in_array($type->getName(), ['mixed', 'object'], true)
                || !$type->isBuiltin() && is_a(Site::class, $type->getName(), true)
            );
        }

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $member) {
                if (self::acceptsSite($member)) {
                    return true;
                }
            }

            return false;
        }

        if ($type instanceof ReflectionIntersectionType) {
            foreach ($type->getTypes() as $member) {
                if (!self::acceptsSite($member)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }
}
