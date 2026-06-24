<?php

declare(strict_types=1);

namespace Garner\Support;

interface IdGenerator
{
    public function generate(): string;
}
