<?php

declare(strict_types=1);

namespace Garner\Core;

use ErrorException;
use InvalidArgumentException;
use Lemmon\Validator\ValidationException;
use Throwable;

final class ErrorHandler
{
    public function __construct(
        private readonly Application $app,
    ) {}

    public function register(): void
    {
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');

        error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

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
        $isDevelopment =
            (string) $this->app->config('app.environment', 'development') !== 'production';
        $title = 'Garner CMS Error';
        $body = '<h1>Application error</h1><p>The request could not be completed.</p>';

        if ($isDevelopment) {
            $body .= sprintf('<pre>%s</pre>', htmlspecialchars(
                $throwable->getMessage(),
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8',
            ));
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
}
