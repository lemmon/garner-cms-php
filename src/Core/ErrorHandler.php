<?php

declare(strict_types=1);

namespace Garner\Core;

use ErrorException;
use Garner\Render\RenderedResponse;
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

    private function respondHtml(Throwable $throwable): never
    {
        $title = 'Garner Error';
        $debug = (bool) $this->app->config('app.debug', false);

        if ($debug) {
            $rendered = $this->renderDebugHtml($throwable);

            if ($rendered !== null) {
                $this->emit(RenderedResponse::html($rendered['body'], $rendered['status']));
            }

            $this->emit(RenderedResponse::html($this->fallbackDebugHtml($title, $throwable), 500));
        }

        $response = $this->renderProductionErrorResponse();

        if ($response !== null) {
            $this->emit($response);
        }

        $this->emit(RenderedResponse::html(
            '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>'
            . $title
            . '</title></head><body><main><h1>Application error</h1>'
            . '<p>The request could not be completed.</p></main></body></html>',
            500,
        ));
    }

    private function emit(RenderedResponse $response): never
    {
        $response->send();
        exit();
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

    private function fallbackDebugHtml(string $title, Throwable $throwable): string
    {
        return sprintf(
            '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>%1$s</title></head>'
            . '<body><main><h1>%2$s</h1><p>%3$s</p><p><code>%4$s:%5$d</code></p><pre>%6$s</pre>'
            . '</main></body></html>',
            $title,
            htmlspecialchars(get_class($throwable), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($throwable->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($throwable->getFile(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $throwable->getLine(),
            htmlspecialchars($throwable->getTraceAsString(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );
    }

    private function renderProductionErrorResponse(): ?RenderedResponse
    {
        try {
            $site = $this->app->siteLoader()->load();
            $path = $this->app->request()->path();

            return new RenderedResponse(
                body: $this->app->siteRenderer()->renderError($site, 500, 'application_error', [
                    'path' => $path,
                    'error_title' => 'Application Error',
                ]),
                status: 500,
                contentType: 'text/html; charset=utf-8',
            );
        } catch (Throwable) {
            return null;
        }
    }
}
