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

    /**
     * Co-located filenames outside the entry-file family: the shared naming
     * convention behind ContentIndex, PageLoader, and TreeValidator's
     * independent notions of "routable directory" — one source, so a rename
     * of either convention can't silently drift the three out of sync.
     */
    public const CONTROLLER = '+controller.php';
    public const ACTION = '+action.php';

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

    /**
     * Absolute path to $dir's +controller.php, or null if absent. Shared by
     * ContentIndex, PageLoader, and TreeValidator so "does this directory
     * have a controller" is resolved in exactly one place instead of each
     * reimplementing the same is_file() check.
     */
    public static function controllerFile(string $dir): ?string
    {
        return self::siblingFile($dir, self::CONTROLLER);
    }

    /**
     * Absolute path to $dir's +action.php, or null if absent. See controllerFile().
     */
    public static function actionFile(string $dir): ?string
    {
        return self::siblingFile($dir, self::ACTION);
    }

    /**
     * Whether $dir carries a +controller.php and/or +action.php. Combined
     * with find() === null by the caller, this is the "routable directory
     * with no entry file" check TreeValidator needs to decide whether an
     * otherwise-empty directory is a route endpoint. ContentIndex answers
     * the same question inline instead of calling this — it needs each
     * file's mtime, not just presence, so it folds the two into one pass
     * rather than checking presence here and re-deriving the mtime after.
     */
    public static function hasEndpointFiles(string $dir): bool
    {
        return self::controllerFile($dir) !== null || self::actionFile($dir) !== null;
    }

    /**
     * Absolute path to $dir's $name file, or null if absent. Shared existence
     * check behind controllerFile()/actionFile() above, and public so callers
     * needing the same is_file()-then-path resolution for a different
     * co-located filename (e.g. PageLoader's +template.twig lookup) aren't
     * left reimplementing it themselves.
     */
    public static function siblingFile(string $dir, string $name): ?string
    {
        $path = $dir . '/' . $name;

        return is_file($path) ? $path : null;
    }
}
