<?php

declare(strict_types=1);

namespace Garner\Support;

/**
 * Environment variable access that works regardless of php.ini variables_order.
 * The stock ini ships "GPCS" — $_ENV stays empty and real environment variables
 * surface only through $_SERVER / getenv() — while Symfony Dotenv populates
 * $_ENV/$_SERVER. The process environment (getenv) is checked FIRST: Dotenv never
 * writes to it, but it also skips it when deciding whether a real variable already
 * exists, so a putenv()-style value would otherwise be shadowed in $_ENV by the
 * file value. This order keeps "real env always wins" true in every combination.
 * HTTP_-prefixed names are refused: in $_SERVER those are attacker-controlled
 * request headers, not environment variables.
 */
final class Env
{
    public static function get(string $name): ?string
    {
        if (str_starts_with($name, 'HTTP_')) {
            return null;
        }

        $real = getenv($name);

        if (is_string($real)) {
            return $real;
        }

        $value = $_ENV[$name] ?? $_SERVER[$name] ?? null;

        return is_string($value) ? $value : null;
    }
}
