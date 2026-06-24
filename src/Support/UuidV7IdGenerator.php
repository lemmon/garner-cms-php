<?php

declare(strict_types=1);

namespace Garner\Support;

use Symfony\Component\Uid\Uuid;

/**
 * Adapts symfony/uid's UUID v7 (lowercase, time-sortable) to the Garner IdGenerator seam.
 */
final class UuidV7IdGenerator implements IdGenerator
{
    public function generate(): string
    {
        return Uuid::v7()->toRfc4122();
    }
}
