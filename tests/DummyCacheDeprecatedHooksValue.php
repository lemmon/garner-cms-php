<?php

declare(strict_types=1);

namespace Garner\Tests;

/**
 * Emits the harmless diagnostic noise a real application object can produce
 * after a PHP or dependency upgrade: a deprecation while being serialized and
 * a notice while being unserialized. Neither means the value failed to
 * round-trip, so Cache must treat them as neither a write failure nor a
 * corrupt row.
 */
final class DummyCacheDeprecatedHooksValue
{
    public function __construct(
        public string $name = 'unset',
    ) {}

    /**
     * @return array{name: string}
     */
    public function __serialize(): array
    {
        trigger_error('deprecated call inside __serialize', E_USER_DEPRECATED);

        return ['name' => $this->name];
    }

    /**
     * @param array{name: string} $data
     */
    public function __unserialize(array $data): void
    {
        trigger_error('notice inside __unserialize', E_USER_NOTICE);

        $this->name = $data['name'];
    }
}
