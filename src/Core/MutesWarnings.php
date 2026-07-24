<?php

declare(strict_types=1);

namespace Garner\Core;

/**
 * Shared by every class that must tolerate an expected filesystem warning —
 * a file vanishing mid-read, the loser of an mkdir() race — as an outcome
 * its caller already handles via the return value, not an error to escalate.
 */
trait MutesWarnings
{
    /**
     * Run $callback with a warning-swallowing handler swapped in for its
     * duration, rather than the `@` operator: Garner's registered error
     * handler promotes warnings to ErrorException, and `@` only works if
     * every installed handler checks error_reporting() — swapping the
     * handler makes no such assumption.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function muted(callable $callback): mixed
    {
        set_error_handler(static fn(): bool => true);

        try {
            return $callback();
        } finally {
            restore_error_handler();
        }
    }
}
