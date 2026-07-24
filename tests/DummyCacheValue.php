<?php

declare(strict_types=1);

namespace Garner\Tests;

final class DummyCacheValue
{
    /**
     * @param list<int> $items
     */
    public function __construct(
        public readonly string $name,
        public readonly array $items,
    ) {}
}
