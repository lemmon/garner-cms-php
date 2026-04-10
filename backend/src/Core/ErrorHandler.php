<?php

declare(strict_types=1);

namespace Garner\Core;

use ErrorException;
use Garner\Site\Page;
use Garner\Site\Pages;
use Garner\Site\RenderedResponse;
use Garner\Site\Site;
use InvalidArgumentException;
use Lemmon\Validator\ValidationException;
use Symfony\Component\ErrorHandler\ErrorRenderer\HtmlErrorRenderer;
use Throwable;

final class ErrorHandler
{
    public function __construct(
        private readonly Application $app,
    ) {}

    public function register(): void
    {
        $debug = (bool) $this->app->config('app.debug', false);

        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('display_startup_errors', $debug ? '1' : '0');
        ini_set('log_errors', '1');

        error_reporting($debug ? E_ALL : E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

        set_error_handler(static function (
            int $severity,
            string $message,
            string $file = '',
            int $line = 0,
        ): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }

            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(function (Throwable $throwable): void {
            $this->log($throwable);

            if ($this->isApiRequest()) {
                $this->respondApi($throwable);
            }

            $this->respondHtml($throwable);
        });
    }

    public function log(Throwable $throwable): void
    {
        $message = sprintf(
            '%s: %s in %s:%d',
            get_class($throwable),
            $throwable->getMessage(),
            $throwable->getFile(),
            $throwable->getLine(),
        );

        error_log($message . "\n" . $throwable->getTraceAsString());
    }

    private function isApiRequest(): bool
    {
        $path = Request::path();
        $apiPrefix = (string) $this->app->config('app.routes.api_prefix', '/api');

        return $path === $apiPrefix || str_starts_with($path, rtrim($apiPrefix, '/') . '/');
    }

    private function respondApi(Throwable $throwable): never
    {
        if ($throwable instanceof ValidationException) {
            Response::json([
                'invalid' => true,
                'fields' => $throwable->getFlattenedErrors(),
            ], 400);
        }

        if ($throwable instanceof NotFoundException) {
            Response::json([
                'error' => true,
                'message' => $throwable->getMessage(),
            ], 404);
        }

        if (
            $throwable instanceof \JsonException
            || $throwable instanceof InvalidArgumentException
        ) {
            Response::json([
                'error' => true,
                'message' => $throwable->getMessage(),
            ], 400);
        }

        Response::json([
            'error' => true,
            'message' => 'Application error',
        ], 500);
    }

    private function respondHtml(Throwable $throwable): never
    {
        $title = 'Garner CMS Error';
        $body = '<h1>Application error</h1><p>The request could not be completed.</p>';
        $debug = (bool) $this->app->config('app.debug', false);

        if ($debug) {
            $rendered = $this->renderDebugHtml($throwable);

            if ($rendered !== null) {
                Response::html($rendered['body'], $rendered['status']);
            }

            $body = sprintf(
                '<h1>%1$s</h1><p>%2$s</p><p><code>%3$s:%4$d</code></p><pre>%5$s</pre>',
                htmlspecialchars(get_class($throwable), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($throwable->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($throwable->getFile(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                $throwable->getLine(),
                htmlspecialchars(
                    $throwable->getTraceAsString(),
                    ENT_QUOTES | ENT_SUBSTITUTE,
                    'UTF-8',
                ),
            );

            Response::html(
                '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>'
                . $title
                . '</title></head><body><main>'
                . $body
                . '</main></body></html>',
                500,
            );
        }

        $response = $this->renderProductionErrorResponse();

        if ($response !== null) {
            Response::content(
                body: $response->body(),
                contentType: $response->contentType(),
                status: $response->status(),
            );
        }

        Response::html(
            '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>'
            . $title
            . '</title></head><body><main>'
            . $body
            . '</main></body></html>',
            500,
        );
    }

    /**
     * @return array{body: string, status: int}|null
     */
    private function renderDebugHtml(Throwable $throwable): ?array
    {
        if (!class_exists(HtmlErrorRenderer::class)) {
            return null;
        }

        $renderer = new HtmlErrorRenderer(debug: true);
        $rendered = $renderer->render($throwable);

        return [
            'body' => $rendered->getAsString(),
            'status' => $rendered->getStatusCode(),
        ];
    }

    private function renderProductionErrorResponse(): ?RenderedResponse
    {
        try {
            $pages = new Pages($this->app->pageRepository(), $this->app->pathResolver());
            $site = new Site($this->app->siteRepository()->read(), $pages);
            $path = Request::path();
            $errorContext = [
                'error' => [
                    'kind' => 'application_error',
                    'path' => $path,
                    'status' => 500,
                    'title' => 'Application Error',
                ],
                'error_title' => 'Application Error',
                'path' => $path,
            ];

            $errorPage = $site->errorPage();

            if ($errorPage instanceof Page) {
                $controllerResult = $this->app->pageControllers()->dispatch(
                    $errorPage,
                    $site,
                    $pages,
                    $this->app,
                );

                if ($controllerResult instanceof RenderedResponse) {
                    return new RenderedResponse(
                        body: $controllerResult->body(),
                        status: $controllerResult->status() === 200
                            ? 500
                            : $controllerResult->status(),
                        contentType: $controllerResult->contentType(),
                    );
                }

                return new RenderedResponse(
                    body: $this->app->siteRenderer()->renderPage(
                        $errorPage,
                        $site,
                        $pages,
                        [
                            ...$controllerResult,
                            ...$errorContext,
                        ],
                    ),
                    status: 500,
                    contentType: 'text/html; charset=utf-8',
                );
            }

            return new RenderedResponse(
                body: $this->app->siteRenderer()->renderError(
                    $site,
                    $pages,
                    500,
                    'application_error',
                    $errorContext,
                ),
                status: 500,
                contentType: 'text/html; charset=utf-8',
            );
        } catch (Throwable) {
            return null;
        }
    }
}
