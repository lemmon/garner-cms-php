<?php

declare(strict_types=1);

namespace Garner\Support;

/**
 * The built-in id generators, selectable via `app.ids.generator`. For a custom
 * generator, pass an IdGenerator instance, a callable, or a class-string instead
 * of one of these cases.
 */
enum IdGeneratorType: string
{
    case Cuid2 = 'cuid2';
    case Ulid = 'ulid';
    case UuidV4 = 'uuid_v4';
    case UuidV7 = 'uuid_v7';

    public function create(): IdGenerator
    {
        return match ($this) {
            self::Cuid2 => new Cuid2IdGenerator(),
            self::Ulid => new UlidIdGenerator(),
            self::UuidV4 => new UuidV4IdGenerator(),
            self::UuidV7 => new UuidV7IdGenerator(),
        };
    }
}
