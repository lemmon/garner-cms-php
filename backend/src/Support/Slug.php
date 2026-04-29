<?php

declare(strict_types=1);

namespace Garner\Support;

use Illuminate\Support\Str;

final class Slug
{
    public static function normalize(string $value): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', Str::lower(Str::ascii($value)));

        if (!is_string($slug)) {
            return '';
        }

        return trim($slug, '-');
    }
}
