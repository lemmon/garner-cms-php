<?php

declare(strict_types=1);

namespace Garner\Content;

use RuntimeException;

final class PageLoader
{
    private const TEMPLATE_FILE = '+template.twig';
    private const CONTROLLER_FILE = '+controller.php';

    public function load(string $dir, string $url, ?Pages $pages = null): Page
    {
        $entry = EntryFile::find($dir);

        if ($entry === null) {
            throw new RuntimeException(sprintf('No entry file found in "%s"', $dir));
        }

        $meta = FormatParser::parse($entry);

        if (!is_array($meta)) {
            throw new InvalidEntryException(sprintf('Entry "%s" must decode to an object', $entry));
        }

        PageMeta::assertValid($meta, $entry);

        return new Page(
            id: PageMeta::resolveId($meta, $dir),
            template: PageMeta::template($meta),
            url: $url,
            meta: $meta,
            content: $this->loadContentFiles($dir, $entry),
            draft: PageMeta::isDraft($meta),
            sort: PageMeta::sort($meta),
            templateFile: $this->siblingFile($dir, self::TEMPLATE_FILE),
            controllerFile: $this->siblingFile($dir, self::CONTROLLER_FILE),
            pages: $pages,
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
