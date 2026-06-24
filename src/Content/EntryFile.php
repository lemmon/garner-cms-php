<?php

declare(strict_types=1);

namespace Garner\Content;

use RuntimeException;

/**
 * Resolves the single entry file for a page directory. JSON is the canonical
 * default; YAML is accepted as an alternative. Having more than one entry file
 * in a directory is ambiguous and rejected.
 */
final class EntryFile
{
    public const CANDIDATES = ['+page.json', '+page.yaml', '+page.yml'];

    public static function find(string $dir): ?string
    {
        $found = [];

        foreach (self::CANDIDATES as $candidate) {
            $path = $dir . '/' . $candidate;

            if (is_file($path)) {
                $found[] = $path;
            }
        }

        if (count($found) > 1) {
            throw new RuntimeException(sprintf(
                'Multiple entry files in "%s": %s',
                $dir,
                implode(', ', array_map('basename', $found)),
            ));
        }

        return $found[0] ?? null;
    }
}
