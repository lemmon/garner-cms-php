<?php

declare(strict_types=1);

namespace Garner\Tests;

use Garner\Support\IdGenerator;

/**
 * @internal test double for SessionTest
 */
final class SequentialIdGenerator implements IdGenerator
{
    private int $count = 0;

    public function generate(): string
    {
        return 'id-' . ++$this->count;
    }
}
