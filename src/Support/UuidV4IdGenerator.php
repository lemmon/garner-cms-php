<?php

declare(strict_types=1);

namespace Garner\Support;

use Symfony\Component\Uid\Uuid;

/**
 * Adapts symfony/uid's UUID v4 to the Garner IdGenerator seam.
 */
final class UuidV4IdGenerator implements IdGenerator
{
    public function generate(): string
    {
        return Uuid::v4()->toRfc4122();
    }
}
