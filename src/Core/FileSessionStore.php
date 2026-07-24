<?php

declare(strict_types=1);

namespace Garner\Core;

use RuntimeException;

/**
 * The default SessionStore: one file per session under a directory
 * (`storage/sessions` by default). No extra dependency and no daemon to run,
 * fitting Garner's shared-hosting-friendly stance — the same reasoning that
 * keeps the route index a plain SQLite file rather than requiring a server.
 *
 * Serialized with PHP's serialize(), not JSON. Garner's file-legibility bias
 * applies to content meant for human editing — a session file isn't: nobody
 * hand-edits `storage/sessions/*`, so the tradeoff runs the other way.
 * json_encode()/json_decode() carry known PHP round-trip hazards that don't
 * matter for hand-edited content but do for values a site stores blindly:
 * whole-number floats can lose their type depending on serialize_precision,
 * and json_decode(..., true) always turns an object into a plain array,
 * discarding the fact that it was ever an object. serialize() has neither
 * problem. unserialize() is still restricted to `allowed_classes: false`
 * on read — the value survives as an object (PHP's Incomplete_Class stub)
 * rather than degrading to an array, but the original class is never
 * instantiated, closing off PHP object-injection gadget chains if a session
 * file is ever corrupted or tampered with.
 */
final class FileSessionStore implements SessionStore
{
    use MutesWarnings;

    private const string EXTENSION = '.session';

    private const string TEMP_EXTENSION = '.tmp';

    public function __construct(
        private readonly string $path,
    ) {}

    public function exists(string $id): bool
    {
        return $this->readEnvelope($id) !== null;
    }

    public function read(string $id): array
    {
        return $this->readEnvelope($id)['data'] ?? [];
    }

    public function write(string $id, array $data, int $lifetime): void
    {
        $file = $this->file($id);

        if ($file === null) {
            throw new RuntimeException(sprintf('Invalid session id "%s"', $id));
        }

        $this->ensureDirectory();

        $payload = serialize(['expires' => time() + $lifetime, 'data' => $data]);

        // Write to a uniquely named temp file, then rename() over the real
        // one. rename() on the same filesystem replaces the target in a
        // single step, so a concurrent read (or gc sweep) always sees a
        // complete file — old or new, never empty or half-written. flock
        // being advisory, a LOCK_EX here wouldn't protect the lockless
        // read path; atomicity does, without requiring readers to lock.
        // Unique temp names also mean two overlapping writers can't tread
        // on each other's temp file: last rename wins, with a whole file.
        $temp = $file . '.' . bin2hex(random_bytes(8)) . self::TEMP_EXTENSION;

        if (file_put_contents($temp, $payload) === false) {
            throw new RuntimeException(sprintf('Unable to write session file "%s"', $file));
        }

        // Before the rename makes it visible: session files hold per-visitor
        // state and their names are the bearer-token ids, so other local
        // users must not be able to read them (file_put_contents alone
        // follows the umask, commonly 0644).
        chmod($temp, 0o600);

        if (!rename($temp, $file)) {
            unlink($temp);

            throw new RuntimeException(sprintf('Unable to write session file "%s"', $file));
        }
    }

    public function destroy(string $id): void
    {
        $file = $this->file($id);

        if ($file !== null && is_file($file)) {
            // Muted: a concurrent destroy or gc may have unlinked the file
            // after the is_file() check; either way it's gone, which is the
            // outcome destroy() wants.
            $this->muted(static fn(): bool => unlink($file));
        }
    }

    public function gc(): void
    {
        if (!is_dir($this->path)) {
            return;
        }

        $entries = scandir($this->path);

        foreach ($entries === false ? [] : $entries as $entry) {
            if (str_ends_with($entry, self::TEMP_EXTENSION)) {
                $this->sweepStaleTempFile($this->path . '/' . $entry);

                continue;
            }

            if (!str_ends_with($entry, self::EXTENSION)) {
                continue;
            }

            $id = substr($entry, 0, -strlen(self::EXTENSION));

            // readEnvelope() already deletes an expired file as a side effect
            // of being unable to return it, so a plain read sweeps expired
            // entries without duplicating the expiry check here. A corrupt or
            // unreadable file also comes back null but without that side
            // effect — sweep it too, so it can't linger forever. file() is
            // null for a filename this store never issued; leave those alone.
            if ($this->readEnvelope($id) !== null) {
                continue;
            }

            $file = $this->file($id);

            if ($file !== null && is_file($file)) {
                $this->muted(static fn(): bool => unlink($file));
            }
        }
    }

    /**
     * A temp file is normally renamed away within the same write() call; one
     * still present is the residue of a crash between write and rename.
     * Give an in-flight write a generous minute before sweeping, so gc
     * can't yank a temp file out from under a writer. Muted: the file can
     * legitimately vanish (the writer's rename) between the scandir and
     * here — expected noise, not an error to escalate.
     */
    private function sweepStaleTempFile(string $file): void
    {
        $this->muted(static function () use ($file): void {
            $modified = filemtime($file);

            if ($modified !== false && $modified < (time() - 60)) {
                unlink($file);
            }
        });
    }

    /**
     * @return array{expires: int, data: array<string, mixed>}|null
     */
    private function readEnvelope(string $id): ?array
    {
        $file = $this->file($id);

        if ($file === null || !is_file($file)) {
            return null;
        }

        // Muted: another request's destroy() or a gc sweep can remove the
        // file between the is_file() check and here, and an unreadable file
        // amounts to the same thing — both are "no session", not errors to
        // escalate through the app's error handler.
        $contents = $this->muted(static fn(): string|false => file_get_contents($file));

        if (!is_string($contents) || $contents === '') {
            return null;
        }

        $decoded = $this->unserialize($contents);

        if (!is_array($decoded) || !is_int($decoded['expires'] ?? null)) {
            return null;
        }

        if ($decoded['expires'] < time()) {
            // Muted: a concurrent reader of the same expired session may
            // have unlinked it first.
            $this->muted(static fn(): bool => unlink($file));

            return null;
        }

        $data = $decoded['data'] ?? [];

        return ['expires' => $decoded['expires'], 'data' => is_array($data) ? $data : []];
    }

    /**
     * unserialize() with warnings muted: malformed contents (a corrupted or
     * truncated file) emit an E_WARNING, and the caller already treats a
     * decode failure as "no session" — expected noise, not a real error to
     * escalate. allowed_classes: false — the envelope is always plain
     * arrays and scalars; refusing to instantiate any class on read closes
     * off object-injection gadget chains from a corrupted or tampered file.
     */
    private function unserialize(string $contents): mixed
    {
        return $this->muted(static fn(): mixed => unserialize($contents, [
            'allowed_classes' => false,
        ]));
    }

    /**
     * The session file path for $id, or null when $id isn't a safe filename
     * component — an id this store never issued (e.g. a tampered cookie
     * value) is rejected outright rather than risking path traversal.
     */
    private function file(string $id): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,255}$/', $id) !== 1) {
            return null;
        }

        return $this->path . '/' . $id . self::EXTENSION;
    }

    private function ensureDirectory(): void
    {
        if (is_dir($this->path)) {
            return;
        }

        // Muted: two simultaneous first writes can both pass the is_dir()
        // check above, and the loser's mkdir() then warns "File exists" —
        // which the app's error handler would promote to an exception
        // before the is_dir() recheck below could conclude that losing the
        // race is fine (the directory exists either way).
        $created = $this->muted(fn(): bool => mkdir($this->path, 0o700, true));

        if (!$created && !is_dir($this->path)) {
            throw new RuntimeException(sprintf(
                'Unable to create session directory "%s"',
                $this->path,
            ));
        }

        // mkdir's mode is filtered through the umask; chmod isn't. Owner-only
        // (0700): on a shared host, a traversable directory would expose the
        // session ids — bearer tokens — as filenames to other local users.
        // Only on creation (the race winner does it): a pre-existing
        // directory keeps whatever permissions the operator chose.
        if ($created) {
            chmod($this->path, 0o700);
        }
    }
}
