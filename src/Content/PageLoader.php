<?php

declare(strict_types=1);

namespace Garner\Content;

use RuntimeException;

final class PageLoader
{
    private const TEMPLATE_FILE = '+template.twig';
    private const CONTROLLER_FILE = '+controller.php';
    private const ACTION_FILE = '+action.php';

    public function __construct(
        private readonly ?MediaPublisher $publisher = null,
        private readonly string $baseUrl = '',
    ) {}

    public function load(string $dir, string $path, ?Pages $pages = null): Page
    {
        $entry = EntryFile::find($dir);
        $controllerFile = $this->siblingFile($dir, self::CONTROLLER_FILE);

        if ($entry === null && $controllerFile === null) {
            throw new RuntimeException(sprintf('No entry file found in "%s"', $dir));
        }

        // A controller-only directory is a route endpoint: no metadata or content
        // (see ContentIndex), it exists only to dispatch its controller.
        $meta = [];
        $content = [];

        if ($entry !== null) {
            $parsed = FormatParser::parse($entry);

            if (!is_array($parsed)) {
                throw new InvalidEntryException(sprintf(
                    'Entry "%s" must decode to an object',
                    $entry,
                ));
            }

            PageMeta::assertValid($parsed, $entry);
            $meta = $parsed;
            $content = $this->loadContentFiles($dir, $entry);
        }

        return new Page(
            id: PageMeta::resolveId($meta, $dir),
            template: PageMeta::template($meta),
            path: $path,
            meta: $meta,
            content: $content,
            dir: $dir,
            draft: PageMeta::isDraft($meta),
            sort: PageMeta::sort($meta),
            templateFile: $this->siblingFile($dir, self::TEMPLATE_FILE),
            controllerFile: $controllerFile,
            actionFile: $this->siblingFile($dir, self::ACTION_FILE),
            pages: $pages,
            publisher: $this->publisher,
            baseUrl: $this->baseUrl,
            endpoint: $entry === null,
        );
    }

    private function siblingFile(string $dir, string $name): ?string
    {
        $path = $dir . '/' . $name;

        return is_file($path) ? $path : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadContentFiles(string $dir, string $entryPath): array
    {
        $names = scandir($dir);

        if ($names === false) {
            return [];
        }

        $content = [];

        foreach ($names as $name) {
            // Reserve the "+" prefix for Garner entry/system files.
            if (str_starts_with($name, '.') || str_starts_with($name, '+')) {
                continue;
            }

            $path = $dir . '/' . $name;

            if (!is_file($path) || $path === $entryPath) {
                continue;
            }

            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            if (!FormatParser::supportsContent($extension)) {
                continue;
            }

            // A structured file beside a real asset (photo.jpg.json next to photo.jpg)
            // is that asset's sidecar metadata, not a content value; skip it.
            if (Page::isAssetSidecar($dir, $name)) {
                continue;
            }

            $key = pathinfo($name, PATHINFO_FILENAME);

            if (array_key_exists($key, $content)) {
                throw new RuntimeException(sprintf(
                    'Content name collision for "%s" in "%s"',
                    $key,
                    $dir,
                ));
            }

            $content[$key] = FormatParser::parse($path);
        }

        ksort($content);

        return $content;
    }
}
