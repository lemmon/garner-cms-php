<?php

declare(strict_types=1);

namespace Garner\Content;

/**
 * Route path normalization shared by the index, the repository, and the public
 * site. The canonical form has a single leading slash and no trailing slashes;
 * the root is "/". Interior duplicate slashes are left alone — they never match
 * an indexed route, so such paths simply resolve to nothing.
 */
final class RoutePath
{
    public static function normalize(string $path): string
    {
        $trimmed = trim($path);

        if ($trimmed === '' || $trimmed === '/') {
            return '/';
        }

        return '/' . trim($trimmed, '/');
    }
}
