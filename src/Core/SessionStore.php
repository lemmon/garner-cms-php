<?php

declare(strict_types=1);

namespace Garner\Core;

/**
 * Pluggable persistence for session data, selected via `app.session.store`
 * the same way `app.ids.generator` selects an IdGenerator. The built-in
 * default is FileSessionStore; a site can supply its own implementation
 * (e.g. backed by Redis or a database) once file storage no longer fits.
 */
interface SessionStore
{
    /**
     * Whether $id names a live session this store issued — i.e. one that can
     * be safely trusted and loaded. Must not create anything. Session uses
     * this to decide whether an incoming cookie value is adopted or
     * discarded: an id that fails this check is treated as no session at
     * all, never as the seed for a new one (session fixation).
     */
    public function exists(string $id): bool;

    /**
     * The stored data for $id, or an empty array when the id is unknown or
     * its data has expired.
     *
     * @return array<string, mixed>
     */
    public function read(string $id): array;

    /**
     * Persist $data under $id, alive for $lifetime seconds from now.
     *
     * @param array<string, mixed> $data
     */
    public function write(string $id, array $data, int $lifetime): void;

    /**
     * Remove $id's data, if any. Safe to call on an id that doesn't exist.
     */
    public function destroy(string $id): void;

    /**
     * Sweep sessions that have expired. Not called on every request — wire
     * it into a deploy hook or cron via `php bin/garner session:gc`, the
     * same way `reindex` is.
     */
    public function gc(): void;
}
