<?php

declare(strict_types=1);

namespace Garner\Support;

final class PhpFile
{
    /**
     * Require a project PHP file in an isolated scope and return its value.
     *
     * A plain `require` inside a method shares that method's local variables
     * (and `$this`), so a top-level assignment in the file — `$path` in a
     * `foreach`, say — would silently overwrite the caller's own variable.
     * Requiring through a static closure gives the file a fresh scope with
     * nothing of the caller's to clobber.
     */
    public static function load(string $file): mixed
    {
        return (static fn(string $file): mixed => require $file)($file);
    }
}
