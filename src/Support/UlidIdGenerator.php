<?php

declare(strict_types=1);

namespace Garner\Support;

use Symfony\Component\Uid\Ulid;

/**
 * Adapts symfony/uid's ULID (sortable, 26-char Crockford base32) to the
 * Garner IdGenerator seam so it can be selected via config or replaced.
 */
final class UlidIdGenerator implements IdGenerator
{
    public function generate(): string
    {
        return new Ulid()->toBase32();
    }
}
