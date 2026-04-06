<?php

declare(strict_types=1);

namespace Garner\Studio;

use Garner\Site\RenderedResponse;
use RuntimeException;

final class StudioApp
{
    public function __construct(
        private readonly string $buildPath,
        private readonly string $prefix = '/studio',
    ) {}

    public function respond(string $path): RenderedResponse
    {
        $indexPath = $this->buildPath . '/index.html';

        if (!is_file($indexPath)) {
            return RenderedResponse::html(
                '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Garner Studio</title></head><body><main><h1>Garner Studio</h1><p>The built Studio app was not found. Run the Studio build first.</p></main></body></html>',
                503,
            );
        }

        $relativePath = $this->relativePath($path);

        if ($relativePath !== '' && $this->looksLikeAssetPath($relativePath)) {
            return $this->respondWithAsset($relativePath);
        }

        $index = file_get_contents($indexPath);

        if (!is_string($index)) {
            throw new RuntimeException('Unable to read the Studio entrypoint');
        }

        return RenderedResponse::html($index);
    }

    private function respondWithAsset(string $relativePath): RenderedResponse
    {
        $normalizedPath = $this->normalizeRelativePath($relativePath);

        if ($normalizedPath === null) {
            return RenderedResponse::text('Invalid Studio asset path', 400);
        }

        $assetPath = $this->buildPath . '/' . $normalizedPath;

        if (!is_file($assetPath)) {
            return RenderedResponse::text('Studio asset not found', 404);
        }

        $asset = file_get_contents($assetPath);

        if (!is_string($asset)) {
            throw new RuntimeException(sprintf(
                'Unable to read Studio asset "%s"',
                $normalizedPath,
            ));
        }

        return new RenderedResponse(
            body: $asset,
            status: 200,
            contentType: $this->contentType($assetPath),
        );
    }

    private function relativePath(string $path): string
    {
        if ($path === $this->prefix) {
            return '';
        }

        if (str_starts_with($path, $this->prefix . '/')) {
            return ltrim(substr($path, strlen($this->prefix)), '/');
        }

        return ltrim($path, '/');
    }

    private function looksLikeAssetPath(string $relativePath): bool
    {
        return pathinfo($relativePath, PATHINFO_EXTENSION) !== '';
    }

    private function normalizeRelativePath(string $relativePath): ?string
    {
        $segments = array_filter(
            explode('/', trim($relativePath, '/')),
            static fn(string $segment): bool => $segment !== '',
        );

        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '..') {
                return null;
            }
        }

        return implode('/', $segments);
    }

    private function contentType(string $path): string
    {
        $detected = mime_content_type($path);
        $contentType = is_string($detected) && $detected !== ''
            ? $detected
            : 'application/octet-stream';

        $needsCharset =
            str_starts_with($contentType, 'text/')
            || $contentType === 'application/javascript'
            || $contentType === 'application/json'
            || $contentType === 'image/svg+xml';

        if ($needsCharset) {
            return $contentType . '; charset=utf-8';
        }

        return $contentType;
    }
}
