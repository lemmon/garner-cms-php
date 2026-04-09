<?php

declare(strict_types=1);

namespace Garner\Support;

use Illuminate\Support\Str;

final class Identifier
{
    public static function kebab(string $value): string
    {
        return self::normalize($value, '-');
    }

    public static function snake(string $value): string
    {
        return self::normalize($value, '_');
    }

    public static function kebabPath(string $value): string
    {
        return self::normalizePath($value, '-');
    }

    private static function normalize(string $value, string $delimiter): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        return (string) Str::of($value)
            ->replaceMatches('/[-_\s]+/', ' ')
            ->trim()
            ->snake($delimiter);
    }

    private static function normalizePath(string $value, string $delimiter): string
    {
        $segments = array_values(array_filter(
            explode('/', str_replace('\\', '/', trim($value))),
            static fn(string $segment): bool => $segment !== '',
        ));

        return implode('/', array_map(static fn(string $segment): string => self::normalize(
            $segment,
            $delimiter,
        ), $segments));
    }
}
