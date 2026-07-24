<?php

declare(strict_types=1);

namespace Garner\Core;

use PDO;
use RuntimeException;

/**
 * Shared owner-only-file enforcement for Cache and Store: both are SQLite
 * files holding runtime or site data that other local users on a shared host
 * must not be able to read, and both self-heal a loosely-permissioned file
 * (planted by another process, or left over from a crash) rather than
 * trusting whatever the filesystem handed back. Callers keep their own
 * is_link() checks and decide when hardening happens relative to connect() —
 * Cache hardens an existing file before opening it (a read-only file must be
 * repaired before PDO can open it for writes), Store always connects first —
 * this trait only holds the identical logic once that ordering is decided.
 */
trait HardensSqliteFile
{
    use MutesWarnings;

    /**
     * Make $path owner-only (0600) or throw. chmod() fails when this process
     * doesn't own the file — most likely a writable file another local user
     * planted — in which case proceeding would put data in a file whose
     * owner can read it. The one exception is a file whose permissions are
     * already owner-only (chmod failed but the goal is already met, e.g.
     * ACL-managed filesystems).
     */
    private function hardenFile(string $path, string $label, string $refusal): void
    {
        if ($this->muted(static fn(): bool => chmod($path, 0o600))) {
            return;
        }

        clearstatcache(false, $path);
        $permissions = $this->muted(static fn(): int|false => fileperms($path));

        if ($permissions === false || ($permissions & 0o077) !== 0) {
            throw new RuntimeException(sprintf(
                'Unable to make %s file "%s" owner-only; %s',
                $label,
                $path,
                $refusal,
            ));
        }
    }

    /**
     * Create $path's parent directory (owner-only, 0700) if it doesn't
     * already exist. A pre-existing directory keeps whatever permissions
     * the operator chose.
     */
    private function ensureFileDirectory(string $path, string $label): void
    {
        $directory = dirname($path);

        if (is_dir($directory)) {
            return;
        }

        // Muted: two simultaneous first writes can both pass the is_dir()
        // check above, and the loser's mkdir() then warns "File exists" —
        // which the app's error handler would promote to an exception
        // before the is_dir() recheck below could conclude that losing the
        // race is fine (the directory exists either way).
        $created = $this->muted(static fn(): bool => mkdir($directory, 0o700, true));

        if (!$created && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create %s "%s"', $label, $directory));
        }

        // mkdir's mode is filtered through the umask; chmod isn't. Only on
        // creation (the race winner does it).
        if ($created) {
            chmod($directory, 0o700);
        }
    }

    /**
     * A PDO SQLite connection to $path, refusing to open it through a
     * symlink — another local user on a shared host must not be able to
     * pre-create $path as a link and redirect reads/writes to a path they
     * chose, with the owner-only chmod hardening the wrong file.
     */
    private function connectSqlite(string $path, string $label): PDO
    {
        if (is_link($path)) {
            throw new RuntimeException(sprintf(
                '%s file "%s" is a symlink; refusing to open it',
                $label,
                $path,
            ));
        }

        return new PDO('sqlite:' . $path, options: [
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            // Wait out a concurrent writer's lock instead of failing
            // immediately — overlapping writes are the normal case.
            PDO::ATTR_TIMEOUT => 5,
        ]);
    }
}
