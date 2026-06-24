<?php

declare(strict_types=1);

namespace Garner\Support;

use Visus\Cuid2\Cuid2;

/**
 * Adapts visus/cuid2 to the Garner IdGenerator seam. CUID2 ids are lowercase,
 * collision-resistant, and start with a letter (24 chars by default).
 */
final class Cuid2IdGenerator implements IdGenerator
{
    public function generate(): string
    {
        return new Cuid2()->toString();
    }
}
