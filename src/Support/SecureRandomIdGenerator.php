<?php

declare(strict_types=1);

namespace Garner\Support;

/**
 * Ids drawn from PHP's CSPRNG (random_bytes): 128 bits of entropy, hex-encoded
 * (32 lowercase chars). For ids that act as bearer tokens — session ids, reset
 * tokens — where unguessability is the whole point. Not selectable via
 * `app.ids.generator`, which exists for scaffolded content ids and may be
 * deliberately predictable; code that needs a secret id constructs this
 * directly.
 */
final class SecureRandomIdGenerator implements IdGenerator
{
    public function generate(): string
    {
        return bin2hex(random_bytes(16));
    }
}
