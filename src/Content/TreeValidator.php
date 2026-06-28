<?php

declare(strict_types=1);

namespace Garner\Content;

use RuntimeException;

/**
 * Read-only integrity check for the content tree. Unlike ContentIndex (which
 * builds the route index and throws on the first problem), this walks every page
 * and collects all issues, for `garner validate` / CI / agents.
 */
final class TreeValidator
{
    public function __construct(
        private readonly string $contentPath,
    ) {}

    /**
     * @return list<ValidationIssue>
     */
    public function validate(): array
    {
        $issues = [];
        $seenIds = [];

        if (is_dir($this->contentPath)) {
            $this->walk($this->contentPath, $issues, $seenIds);
        }

        return $issues;
    }

    /**
     * @param list<ValidationIssue>   $issues
     * @param array<string, string>   $seenIds
     */
    private function walk(string $dir, array &$issues, array &$seenIds): void
    {
        $entry = null;

        try {
            $entry = EntryFile::find($dir);
        } catch (RuntimeException $exception) {
            $issues[] = new ValidationIssue($this->relative($dir), $exception->getMessage());
        }

        if ($entry !== null) {
            $this->checkEntry($dir, $entry, $issues, $seenIds);
        }

        $names = scandir($dir);

        if ($names === false) {
            return;
        }

        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $child = $dir . '/' . $name;

            if (is_dir($child)) {
                $this->walk($child, $issues, $seenIds);
            }
        }
    }

    /**
     * @param list<ValidationIssue> $issues
     * @param array<string, string> $seenIds
     */
    private function checkEntry(string $dir, string $entry, array &$issues, array &$seenIds): void
    {
        try {
            $meta = FormatParser::parse($entry);
        } catch (RuntimeException $exception) {
            $issues[] = new ValidationIssue($this->relative($entry), $exception->getMessage());

            return;
        }

        if (!is_array($meta)) {
            $issues[] = new ValidationIssue(
                $this->relative($entry),
                'Entry must decode to an object',
            );

            return;
        }

        try {
            PageMeta::assertValid($meta, $this->relative($entry));
        } catch (InvalidEntryException $exception) {
            $issues[] = new ValidationIssue($this->relative($entry), $exception->getMessage());
        }

        $id = PageMeta::resolveId($meta, $dir);
        $relative = $this->relative($entry);

        if (array_key_exists($id, $seenIds)) {
            $issues[] = new ValidationIssue($relative, sprintf(
                'Duplicate page id "%s" (already used by "%s")',
                $id,
                $seenIds[$id],
            ));
        }

        $seenIds[$id] ??= $relative;

        $this->checkContentCollisions($dir, $entry, $issues);
    }

    /**
     * @param list<ValidationIssue> $issues
     */
    private function checkContentCollisions(string $dir, string $entryPath, array &$issues): void
    {
        $names = scandir($dir);

        if ($names === false) {
            return;
        }

        $seen = [];

        foreach ($names as $name) {
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

            // Mirror the content loader: a structured file beside an asset is that
            // asset's sidecar, not content, so it never collides.
            if (Page::isAssetSidecar($dir, $name)) {
                continue;
            }

            $key = pathinfo($name, PATHINFO_FILENAME);

            if (array_key_exists($key, $seen)) {
                $issues[] = new ValidationIssue(
                    $this->relative($dir),
                    sprintf('Content name collision for "%s"', $key),
                );

                continue;
            }

            $seen[$key] = true;
        }
    }

    private function relative(string $path): string
    {
        $relative = ltrim(substr($path, strlen($this->contentPath)), '/');

        return $relative === '' ? '.' : $relative;
    }
}
