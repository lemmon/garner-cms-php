<?php

declare(strict_types=1);

namespace Garner\Content;

use Lemmon\Validator\Validator;

/**
 * The +page entry contract. This is the only file Garner constrains, and even it
 * has no required fields — a directory with a `+page.json` is a page.
 *
 * - `created` is optional; when present it must be a non-empty string.
 * - `template` is optional; when absent the renderer falls back to the configured
 *   default template. When present it must be a non-empty string.
 * - `id` is optional; when absent it is inherited from the directory name (an
 *   explicit `id` always wins). Global id uniqueness is enforced by ContentIndex.
 * - `draft` is optional (default false); when true the page 404s publicly and is
 *   excluded from listings. When present it must be a boolean.
 * - All other keys are preserved as freeform metadata.
 */
final class PageMeta
{
    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidEntryException
     */
    public static function assertValid(array $data, string $source): void
    {
        $nonEmptyString = Validator::isString()->required()->minLength(1);
        $errors = [];

        if (array_key_exists('id', $data) && !$nonEmptyString->tryValidate($data['id'])[0]) {
            $errors[] = "'id' must be a non-empty string when present";
        }

        if (
            array_key_exists('template', $data)
            && !$nonEmptyString->tryValidate($data['template'])[0]
        ) {
            $errors[] = "'template' must be a non-empty string when present";
        }

        if (
            array_key_exists('created', $data) && !$nonEmptyString->tryValidate($data['created'])[0]
        ) {
            $errors[] = "'created' must be a non-empty string when present";
        }

        $sort = $data['sort'] ?? null;

        if ($sort !== null && !is_int($sort)) {
            $errors[] = "'sort' must be an integer when present";
        }

        if (array_key_exists('draft', $data) && !is_bool($data['draft'])) {
            $errors[] = "'draft' must be a boolean when present";
        }

        if ($errors !== []) {
            throw new InvalidEntryException(sprintf(
                'Invalid entry "%s": %s',
                $source,
                implode('; ', $errors),
            ));
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function resolveId(array $data, string $dir): string
    {
        $id = $data['id'] ?? null;

        if (is_string($id) && trim($id) !== '') {
            return trim($id);
        }

        return basename($dir);
    }

    /**
     * The declared template, or null when absent (renderer falls back to default).
     *
     * @param array<string, mixed> $data
     */
    public static function template(array $data): ?string
    {
        $template = $data['template'] ?? null;

        return is_string($template) && $template !== '' ? $template : null;
    }

    /**
     * Whether the page is a draft (unpublished: 404s and is hidden from listings).
     * Absent counts as published.
     *
     * @param array<string, mixed> $data
     */
    public static function isDraft(array $data): bool
    {
        return ($data['draft'] ?? false) === true;
    }

    /**
     * Manual ordering weight for listings (lower comes first). Absent counts as 0,
     * so negatives pin to the top and positives sink below the default pages.
     *
     * @param array<string, mixed> $data
     */
    public static function sort(array $data): int
    {
        $sort = $data['sort'] ?? null;

        return is_int($sort) ? $sort : 0;
    }
}
