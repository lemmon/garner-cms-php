<?php

declare(strict_types=1);

namespace Garner\Blueprint;

final class BlueprintException extends \RuntimeException
{
    /**
     * @param list<string> $errors
     */
    public static function validationFailed(string $name, array $errors): self
    {
        return new self(sprintf('Blueprint "%s" is invalid: %s', $name, implode('; ', $errors)));
    }
}
