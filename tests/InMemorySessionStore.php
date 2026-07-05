<?php

declare(strict_types=1);

namespace Garner\Tests;

use Garner\Core\SessionStore;

/**
 * @internal test double for SessionTest
 */
final class InMemorySessionStore implements SessionStore
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $entries = [];

    public function exists(string $id): bool
    {
        return array_key_exists($id, $this->entries);
    }

    public function read(string $id): array
    {
        return $this->entries[$id] ?? [];
    }

    public function write(string $id, array $data, int $lifetime): void
    {
        $this->entries[$id] = $data;
    }

    public function destroy(string $id): void
    {
        unset($this->entries[$id]);
    }

    public function gc(): void {}
}
