<?php

declare(strict_types=1);

namespace Garner\Content;

/**
 * Composite comparator for ordering page arrays.
 *
 * Sort priority: parent_id → status rank → listed sort key → slug → id.
 */
trait SortsPages
{
    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    private static function comparePages(array $left, array $right): int
    {
        return (
            (string) ($left['parent_id'] ?? '') <=> (string) ($right['parent_id'] ?? '')
            // @mago-expect lint:no-shorthand-ternary
            ?: self::statusRank($left) <=> self::statusRank($right)
            // @mago-expect lint:no-shorthand-ternary
            ?: self::listedSortKey($left) <=> self::listedSortKey($right)
            // @mago-expect lint:no-shorthand-ternary
            ?: self::slugKey($left) <=> self::slugKey($right)
            // @mago-expect lint:no-shorthand-ternary
            ?: self::idKey($left) <=> self::idKey($right)
        );
    }

    /**
     * @param array<string, mixed> $page
     */
    private static function statusRank(array $page): int
    {
        return match ($page['status'] ?? null) {
            'listed' => 0,
            'unlisted' => 1,
            'draft' => 2,
            default => 3,
        };
    }

    /**
     * @param array<string, mixed> $page
     */
    private static function listedSortKey(array $page): int
    {
        if (($page['status'] ?? null) !== 'listed') {
            return PHP_INT_MAX;
        }

        return is_int($page['sort'] ?? null) ? $page['sort'] : PHP_INT_MAX;
    }

    /**
     * @param array<string, mixed> $page
     */
    private static function slugKey(array $page): string
    {
        return is_string($page['slug'] ?? null) ? $page['slug'] : '';
    }

    /**
     * @param array<string, mixed> $page
     */
    private static function idKey(array $page): string
    {
        return is_string($page['id'] ?? null) ? $page['id'] : '';
    }
}
