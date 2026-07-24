<?php

declare(strict_types=1);

namespace Garner\Core;

use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;
use SplObjectStorage;
use Throwable;

/**
 * Disposable application cache for computed values, obtained through
 * Application::cache(). Unlike Store, cache data is derived runtime state:
 * it may expire, cache:clear may remove it, and deployments do not back it up.
 *
 * Values use PHP serialization rather than JSON. A cache is process-managed
 * machinery, not an authoring or interchange format, so transparent PHP
 * round-trips matter more than inspectability: empty arrays and empty objects
 * stay distinct, floats keep their type, and serializable application objects
 * retain their class. Classes are allowed during unserialization because only
 * Garner-written, owner-only runtime data reaches this decoder; request and
 * content payloads must never be passed to it as encoded cache entries.
 *
 * Backed by SQLite (`runtime/cache/data.sqlite` by default), created lazily on
 * first set(). Reads, removes, and clears against a cache that was never used do
 * not create it. Expired or corrupt rows behave as misses and are removed, with
 * one asymmetry: has() answers from a row's presence and expiry alone and never
 * decodes the payload, so a corrupt payload is detected — and its row removed —
 * only when get() or remember() reads it. A database file that is corrupt at
 * the file level (not just a bad row) behaves as an empty cache on reads
 * (get()/has()/remember()) and throws a clear
 * RuntimeException on writes and on clear() — rather than leaking a raw
 * PDOException, or, for clear(), silently reporting success while leaving a
 * file behind that fails every subsequent set().
 */
final class Cache
{
    use HardensSqliteFile;

    /**
     * Ceilings for the pre-write and post-read value inspections. The
     * container budget counts every array and object the walker visits, so
     * it bounds both what a value may contain and what the inspection
     * itself may cost per set() or get(). It is sized so a large decoded
     * API response — the cache's headline use case, easily tens of
     * thousands of arrays for a multi-megabyte feed — passes, while a
     * runaway or adversarial structure still fails fast.
     */
    private const int MAX_INSPECTION_CONTAINERS = 100_000;

    private const int MAX_INSPECTION_DEPTH = 64;

    private ?PDO $pdo = null;

    private bool $schemaReady = false;

    private bool $hardened = false;

    /**
     * Set when reader() caught a PDOException opening or querying the file
     * (a database file corrupt at the file level), as opposed to a clean
     * "no cache table yet" — the other reason reader() returns null. Reads
     * treat both the same way (a miss), but clear() needs to tell them
     * apart: reporting "already clear" for a corrupt file would be a lie,
     * and would leave a file behind that fails every subsequent set().
     */
    private bool $unusable = false;

    public function __construct(
        private readonly string $path,
    ) {}

    /**
     * The SQLite file this instance reads and writes. Lets callers (e.g.
     * cache:clear) check for the cache the *active* instance actually uses,
     * rather than recomputing a path that could differ under withCache().
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Store $value under $key. Null means no automatic expiry; a TTL at or
     * below zero removes any existing value instead of creating a dead row.
     */
    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        if ($ttl !== null && $ttl <= 0) {
            $this->remove($key);

            return;
        }

        $payload = $this->encode($key, $value);
        $expires = $ttl === null ? null : $this->clampedExpiry($ttl);
        $pdo = $this->writer();

        try {
            $pdo->prepare(
                'DELETE FROM cache WHERE expires IS NOT NULL AND expires <= :now',
            )->execute([
                ':now' => time(),
            ]);

            $statement = $pdo->prepare(
                'INSERT INTO cache (key, value, expires)'
                . ' VALUES (:key, :value, :expires)'
                . ' ON CONFLICT (key) DO UPDATE SET'
                . ' value = excluded.value, expires = excluded.expires',
            );
            $statement->execute([
                ':key' => $key,
                ':value' => $payload,
                ':expires' => $expires,
            ]);
        } catch (PDOException $exception) {
            // writer() having connected and prepared the schema doesn't
            // guarantee the table's own pages are readable — that can only
            // surface once a query actually touches them.
            $this->unusable = true;

            throw new RuntimeException(
                sprintf('Unable to write cache value for "%s": %s', $key, $exception->getMessage()),
                previous: $exception,
            );
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $cached = $this->lookup($key);

        return $cached['found'] ? $cached['value'] : $default;
    }

    /**
     * Whether a live value exists for $key, answered from the row's presence
     * and expiry alone. The payload is never decoded — a has()-then-get()
     * caller would otherwise pay two full unserialize() passes per hit, and
     * an existence check should not run a cached object's __unserialize
     * hooks. The tradeoff: a row whose payload get() would reject as corrupt
     * still counts as present here until a get() or remember() removes it.
     */
    public function has(string $key): bool
    {
        $pdo = $this->reader();

        if ($pdo === null) {
            return false;
        }

        try {
            $statement = $pdo->prepare('SELECT expires FROM cache WHERE key = :key LIMIT 1');
            $statement->execute([':key' => $key]);
            $row = $statement->fetch();
            $statement->closeCursor();
        } catch (PDOException) {
            // Same contract as lookup(): table pages discovered unreadable
            // by an actual query are a miss, and $unusable keeps clear()
            // from claiming success over the file.
            $this->unusable = true;

            return false;
        }

        if (!is_array($row)) {
            return false;
        }

        $expires = $row['expires'] ?? null;

        if ($expires === null || is_int($expires) && $expires > time()) {
            return true;
        }

        // Expired, or an expires value lookup() would reject as corrupt:
        // delegate to lookup() so its observed-row cleanup removes the row
        // without racing a concurrent legitimate refresh.
        return $this->lookup($key)['found'];
    }

    /**
     * Return the cached value when present; otherwise compute, store, and
     * return it. Concurrent misses may both run the callback (no single-flight
     * lock in v1), but SQLite keeps their resulting writes complete and safe.
     *
     * @param callable(): mixed $callback
     */
    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $cached = $this->lookup($key);

        if ($cached['found']) {
            return $cached['value'];
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    public function remove(string $key): void
    {
        if ($this->pdo === null && !is_file($this->path) && !is_link($this->path)) {
            return;
        }

        $pdo = $this->reader();

        if ($pdo === null) {
            return;
        }

        try {
            $pdo->prepare('DELETE FROM cache WHERE key = :key')->execute([':key' => $key]);
        } catch (PDOException) {
            // Best-effort, like the rest of remove()'s "nothing to remove"
            // handling above — a query failing because the table's pages
            // are unreadable is still nothing this call can fix.
            $this->unusable = true;
        }
    }

    /**
     * Delete every cached value. Returns whether there was a cache to clear:
     * false when the file has no `cache` table yet (never written by this
     * class, or a foreign file sitting at this path), so a caller like
     * cache:clear can report "already clear" instead of claiming a no-op
     * as a success.
     *
     * Throws when the file exists but is unusable (corrupt at the file
     * level) rather than reporting either outcome: reporting "cleared"
     * would be false, and reporting "already clear" would silently leave a
     * file behind that fails every subsequent set() — clear() is the one
     * call a caller relies on for a clean slate, so it must say so plainly
     * when it can't deliver one.
     */
    public function clear(): bool
    {
        if ($this->pdo === null && !is_file($this->path) && !is_link($this->path)) {
            return false;
        }

        $pdo = $this->reader();

        if ($pdo === null) {
            if ($this->unusable) {
                $this->throwUnusable();
            }

            return false;
        }

        try {
            $pdo->exec('DELETE FROM cache');
        } catch (PDOException $exception) {
            // reader() having confirmed the table's sqlite_master entry
            // doesn't guarantee its own pages are readable — that can only
            // surface once a query actually touches them.
            $this->unusable = true;
            $this->throwUnusable($exception);
        }

        return true;
    }

    private function throwUnusable(?Throwable $previous = null): never
    {
        throw new RuntimeException(
            sprintf('Cache file "%s" is unusable and was not cleared', $this->path),
            previous: $previous,
        );
    }

    /**
     * @return array{found: bool, value: mixed}
     */
    private function lookup(string $key): array
    {
        $pdo = $this->reader();

        if ($pdo === null) {
            return ['found' => false, 'value' => null];
        }

        try {
            $statement = $pdo->prepare('SELECT value, expires FROM cache WHERE key = :key LIMIT 1');
            $statement->execute([':key' => $key]);
            $row = $statement->fetch();
            $statement->closeCursor();
        } catch (PDOException) {
            // reader() only confirmed the `cache` table exists in
            // sqlite_master; its own b-tree pages can still be corrupted
            // (partial disk damage, a crash mid-write) and that only
            // surfaces once a query actually touches them. Same contract
            // as any other unusable file: a miss for reads, and $unusable
            // means clear() won't silently claim success over it either.
            $this->unusable = true;

            return ['found' => false, 'value' => null];
        }

        if (!is_array($row)) {
            return ['found' => false, 'value' => null];
        }

        $rawValue = $row['value'] ?? null;
        $rawExpires = $row['expires'] ?? null;

        // A value that isn't a string, or an expires that isn't null/int
        // (e.g. a row written outside Cache, or a pre-fix float left behind
        // by an overflowing TTL), can't be trusted — remove it like any
        // other corrupt row instead of leaving it to fail every future read.
        if (!is_string($rawValue) || $rawExpires !== null && !is_int($rawExpires)) {
            $this->removeObservedRow($pdo, $key, $rawValue, $rawExpires);

            return ['found' => false, 'value' => null];
        }

        if ($rawExpires !== null && $rawExpires <= time()) {
            $this->removeObservedRow($pdo, $key, $rawValue, $rawExpires);

            return ['found' => false, 'value' => null];
        }

        $decoded = $this->decode($rawValue);

        if (!$decoded['valid']) {
            $this->removeObservedRow($pdo, $key, $rawValue, $rawExpires);

            return ['found' => false, 'value' => null];
        }

        return ['found' => true, 'value' => $decoded['value']];
    }

    private function removeObservedRow(PDO $pdo, string $key, mixed $value, mixed $expires): void
    {
        // CAST(... AS TEXT) rather than typed parameter binding: `value`
        // has no column affinity, so a row written outside Cache (or
        // corrupted) can hold any SQLite storage class — BLOB, REAL,
        // INTEGER — and PDO has no way to rebind a fetched PHP value back
        // as its exact original storage class (no PARAM_* for REAL, and
        // PHP has only one string type for both TEXT and BLOB). Casting
        // both sides to TEXT compares them the same way regardless of
        // storage class. This can't over-match a concurrent legitimate
        // refresh: that always writes a TEXT serialize() payload, and two
        // different TEXT values never cast-collide with each other.
        $statement = $pdo->prepare(
            'DELETE FROM cache WHERE key = :key'
            . ' AND CAST(value AS TEXT) IS CAST(:value AS TEXT)'
            . ' AND CAST(expires AS TEXT) IS CAST(:expires AS TEXT)',
        );
        $statement->bindValue(':key', $key, PDO::PARAM_STR);
        $statement->bindValue(':value', $value, $this->paramType($value));
        $statement->bindValue(':expires', $expires, $this->paramType($expires));
        $statement->execute();
    }

    private function paramType(mixed $value): int
    {
        return $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR;
    }

    /**
     * time() + $ttl as a plain int, clamped rather than left to overflow to
     * a float when $ttl is astronomically large (e.g. a caller passing
     * PHP_INT_MAX to mean "keep this effectively forever"). An unclamped sum
     * would silently become a float, which the INTEGER-affinity `expires`
     * column stores as a REAL — a row lookup() would then have to reject as
     * corrupt on every future read.
     */
    private function clampedExpiry(int $ttl): int
    {
        $now = time();

        return $ttl > (PHP_INT_MAX - $now) ? PHP_INT_MAX : $now + $ttl;
    }

    private function encode(string $key, mixed $value): string
    {
        if ($this->containsUnsafeSerializationValue($value)) {
            throw new InvalidArgumentException(sprintf(
                'Cache value for "%s" contains a resource or exceeds safe inspection limits',
                $key,
            ));
        }

        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            // Only warning-or-worse severities signal a failed serialization.
            // A value's own __serialize/__sleep may emit harmless E_DEPRECATED
            // or E_NOTICE noise (routine after a PHP or dependency upgrade);
            // treating that as failure would turn a working write into an
            // exception. Every severity is still swallowed for the duration:
            // returning false would hand deprecations to the app's handler,
            // which promotes them to ErrorException in debug mode — the same
            // spurious failure by another path.
            if (($severity & (E_DEPRECATED | E_USER_DEPRECATED | E_NOTICE | E_USER_NOTICE)) === 0) {
                $warning = $message;
            }

            return true;
        });

        try {
            $payload = serialize($value);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException(
                sprintf(
                    'Cache value for "%s" cannot be serialized (%s)',
                    $key,
                    $exception->getMessage(),
                ),
                previous: $exception,
            );
        } finally {
            restore_error_handler();
        }

        if ($warning !== null) {
            throw new InvalidArgumentException(sprintf(
                'Cache value for "%s" cannot be serialized (%s)',
                $key,
                $warning,
            ));
        }

        return $payload;
    }

    /**
     * @return array{valid: bool, value: mixed}
     */
    private function decode(string $payload): array
    {
        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            // unserialize() reports a malformed payload at E_WARNING. A
            // cached object's own __unserialize/__wakeup may additionally
            // emit harmless E_DEPRECATED or E_NOTICE noise (routine after a
            // PHP or dependency upgrade); treating that as corruption would
            // delete the row and turn every read of the key into a miss that
            // re-runs remember()'s callback forever. Every severity is still
            // swallowed for the duration: returning false would hand
            // deprecations to the app's handler, which promotes them to
            // ErrorException in debug mode — the same spurious rejection by
            // another path.
            if (($severity & (E_DEPRECATED | E_USER_DEPRECATED | E_NOTICE | E_USER_NOTICE)) === 0) {
                $warning = $message;
            }

            return true;
        });

        try {
            $value = unserialize($payload, ['allowed_classes' => true]);
        } catch (Throwable) {
            return ['valid' => false, 'value' => null];
        } finally {
            restore_error_handler();
        }

        if ($warning !== null || $this->containsIncompleteOrUninspectableValue($value)) {
            return ['valid' => false, 'value' => null];
        }

        return ['valid' => true, 'value' => $value];
    }

    private function containsUnsafeSerializationValue(mixed $value): bool
    {
        $remaining = self::MAX_INSPECTION_CONTAINERS;

        return $this->exceedsInspectionLimits(
            $value,
            0,
            new SplObjectStorage(),
            $remaining,
            static fn(mixed $item): bool => is_resource($item),
        );
    }

    private function containsIncompleteOrUninspectableValue(mixed $value): bool
    {
        $remaining = self::MAX_INSPECTION_CONTAINERS;

        return $this->exceedsInspectionLimits(
            $value,
            0,
            new SplObjectStorage(),
            $remaining,
            static fn(mixed $item): bool => $item instanceof \__PHP_Incomplete_Class,
        );
    }

    /**
     * Shared recursive walker behind encode()'s resource/limit check and
     * decode()'s incomplete-class/limit check — identical traversal, depth
     * budget, and object cycle detection; only the leaf test differs between
     * the two callers.
     *
     * An object's children are always its raw property table
     * (get_mangled_object_vars()), never __serialize()'s return value: a
     * class implementing __serialize() must be invoked to get that value,
     * and invoking it here as well as inside the real serialize() call
     * below would run it twice — wrong for anything stateful or
     * non-idempotent, and a value the preflight check validated could then
     * differ from the value actually written. Such objects are opaque to
     * this check by contract; they own producing resource-free serialized
     * state themselves.
     *
     * A recursive array (`$a['self'] = &$a`) is not specifically detected —
     * it runs the depth budget out like any other 64-level structure and is
     * rejected. That is deliberate, not an oversight: it keeps this walker
     * free of array-identity tracking (PHP arrays have no cheap identity
     * check the way objects do) for a shape application code essentially
     * never produces on purpose.
     *
     * @param SplObjectStorage<object, null> $seen Objects already walked,
     *   created once by the caller and threaded through every recursive
     *   call — including array branches — so a single object referenced
     *   from many places (safe and non-cyclic) is inspected once instead of
     *   being re-charged against $remaining on every occurrence.
     * @param callable(mixed): bool $isUnsafeLeaf
     */
    private function exceedsInspectionLimits(
        mixed $value,
        int $depth,
        SplObjectStorage $seen,
        int &$remaining,
        callable $isUnsafeLeaf,
    ): bool {
        if ($isUnsafeLeaf($value)) {
            return true;
        }

        if (!is_array($value) && !is_object($value)) {
            return false;
        }

        // Checked before the depth/remaining gate below: an object already
        // walked once (found safe, or its check would have returned true
        // and aborted the whole call) is free to skip regardless of how
        // deep or how many times it's referenced again.
        if (is_object($value) && $seen->offsetExists($value)) {
            return false;
        }

        if ($depth >= self::MAX_INSPECTION_DEPTH || $remaining <= 0) {
            return true;
        }

        $remaining--;

        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->exceedsInspectionLimits(
                    $item,
                    $depth + 1,
                    $seen,
                    $remaining,
                    $isUnsafeLeaf,
                )) {
                    return true;
                }
            }

            return false;
        }

        $seen->offsetSet($value);

        foreach (get_mangled_object_vars($value) as $item) {
            if ($this->exceedsInspectionLimits(
                $item,
                $depth + 1,
                $seen,
                $remaining,
                $isUnsafeLeaf,
            )) {
                return true;
            }
        }

        return false;
    }

    private function reader(): ?PDO
    {
        if ($this->pdo === null) {
            if (!is_file($this->path) && !is_link($this->path)) {
                return null;
            }

            $this->harden();

            try {
                $this->pdo = $this->connect();
            } catch (PDOException) {
                $this->unusable = true;

                return null;
            }
        }

        if (!$this->schemaReady) {
            try {
                $statement = $this->pdo->query(
                    "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'cache'",
                );
            } catch (PDOException) {
                $this->unusable = true;

                return null;
            }

            if ($statement === false || $statement->fetch() === false) {
                return null;
            }

            $this->schemaReady = true;
        }

        return $this->pdo;
    }

    private function writer(): PDO
    {
        if ($this->pdo === null) {
            $this->ensureDirectory();

            if (!is_file($this->path) && !is_link($this->path)) {
                // SQLite would create a missing file with the umask's default
                // (usually world-readable) permissions, and another local
                // user could open it in the window before a post-connect
                // chmod. Pre-create it owner-only instead — a zero-length
                // file is a valid empty database to SQLite. touch() failing
                // (an unwritable directory) is left for connect() to report
                // as the failure to open.
                $path = $this->path;
                $previousUmask = umask(0o177);

                try {
                    $this->muted(static fn(): bool => touch($path));
                } finally {
                    umask($previousUmask);
                }
            }

            if (is_file($this->path) || is_link($this->path)) {
                // A pre-existing file may have loose or read-only
                // permissions (planted, or left over from a crash); repair
                // them before connect() so a read-only file doesn't fail to
                // open for writing in the first place.
                $this->harden();
            }

            try {
                $this->pdo = $this->connect();
            } catch (PDOException $exception) {
                throw new RuntimeException(
                    sprintf(
                        'Unable to open cache file "%s": %s',
                        $this->path,
                        $exception->getMessage(),
                    ),
                    previous: $exception,
                );
            }

            // Backstop for the touch() failure path: if connect() still
            // managed to create the file, it has the umask's permissions.
            // A no-op when harden() already ran above.
            $this->harden();
        }

        if (!$this->schemaReady) {
            try {
                $this->pdo->exec(
                    'CREATE TABLE IF NOT EXISTS cache ('
                    . 'key TEXT PRIMARY KEY, value BLOB NOT NULL, expires INTEGER NULL)',
                );
                // Partial index: only expired rows are ever looked up by
                // this column (the DELETE in set()), so indexing NULL
                // (never-expiring) rows would only add bulk for nothing.
                $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_cache_expires ON cache (expires)'
                . ' WHERE expires IS NOT NULL');
            } catch (PDOException $exception) {
                throw new RuntimeException(
                    sprintf(
                        'Unable to prepare cache file "%s": %s',
                        $this->path,
                        $exception->getMessage(),
                    ),
                    previous: $exception,
                );
            }

            $this->schemaReady = true;
        }

        return $this->pdo;
    }

    private function harden(): void
    {
        if ($this->hardened) {
            return;
        }

        if (is_link($this->path)) {
            throw new RuntimeException(sprintf(
                'Cache file "%s" is a symlink; refusing to open it',
                $this->path,
            ));
        }

        $this->hardenFile($this->path, 'cache', 'refusing to use runtime data');
        $this->hardened = true;
    }

    private function connect(): PDO
    {
        return $this->connectSqlite($this->path, 'Cache');
    }

    private function ensureDirectory(): void
    {
        $this->ensureFileDirectory($this->path, 'cache directory');
    }
}
