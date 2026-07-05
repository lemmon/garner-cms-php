<?php

declare(strict_types=1);

namespace Garner\Tests;

/**
 * @internal test double for FileSessionStoreTest — anonymous classes cannot
 * be serialize()'d, so this needs a real name.
 */
final class DummySessionValue
{
    public string $name = 'alice';
}
