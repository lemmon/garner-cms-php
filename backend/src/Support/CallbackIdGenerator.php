<?php

declare(strict_types=1);

namespace Garner\Support;

use Closure;
use RuntimeException;

final class CallbackIdGenerator implements IdGenerator
{
    private readonly Closure $callback;

    public function __construct(callable $callback)
    {
        $this->callback = $callback(...);
    }

    public function generate(): string
    {
        $id = ($this->callback)();

        if (!is_string($id) || trim($id) === '') {
            throw new RuntimeException('Configured ID generator must return a non-empty string');
        }

        return trim($id);
    }
}
