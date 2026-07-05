<?php

declare(strict_types=1);

namespace Garner\Core;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use JsonException;
use PDO;
use RuntimeException;

/**
 * Durable site-wide key-value storage — string keys, JSON values — obtained
 * via Application::store(). This is what a +action.php reaches for when a
 * form submission needs to *keep* something. It is deliberately not a
 * database layer: no queries into values, no expiry, no multiple stores.
 * A site that outgrows key-value lookup brings its own PDO connection and
 * schema (its own file under storage/) rather than growing this into a
 * query builder.
 *
 * Not the session store: sessions are per-visitor, expiring state; the
 * store holds data a site wants to remember indefinitely, keyed by what it
 * is rather than who sent it.
 *
 * Backed by a single SQLite file (storage/store.sqlite by default), created
 * lazily on first write — a site that never touches the store never grows
 * the file, and reads against a store that was never written to just answer
 * "not there". SQLite rather than per-key JSON files because the write path
 * decides it: concurrent form POSTs need atomic insert-if-absent (add())
 * and safe concurrent writes, which SQLite provides for free. The
 * legibility cost is mitigated by values being JSON in a plain TEXT column
 * — inspectable via the store:* console commands or the sqlite3 CLI — and
 * the file is canonical, unlike runtime/index.sqlite: back up storage/,
 * ignore runtime/.
 *
 * The value contract is "JSON-encodable in, decoded value out": scalars,
 * lists, and maps round-trip; objects don't — an object is encoded to its
 * JSON shape and comes back as an array, by contract rather than by
 * accident. Values are stored with JSON_PRESERVE_ZERO_FRACTION, so
 * whole-number floats keep their type through a round-trip (2.0 stays
 * float(2.0), not int(2)).
 *
 * Key construction stays in userland: Garner does not hash, normalize, or
 * namespace on the site's behalf. The convention for multi-item data is one
 * key per item under a prefix ("email:<hash>"), not one growing array under
 * a single key — that makes uniqueness structural (add() is the atomic
 * "already there?" answer) and gives prefix reads (items(), count()) their
 * natural shape.
 */
final class Store
{
    private ?PDO $pdo = null;

    /**
     * Whether the store table is known to exist: confirmed by a reader
     * (sqlite_master) or guaranteed by a writer (CREATE TABLE IF NOT
     * EXISTS). Never unlearned — the table is never dropped.
     */
    private bool $schemaReady = false;

    private bool $hardened = false;

    public function __construct(
        private readonly string $path,
    ) {}

    /**
     * Upsert: store $value under $key, replacing any previous value. For
     * keys that are genuinely mutable (counters, site state, settings);
     * use add() when the key existing already means "stop".
     */
    public function set(string $key, mixed $value): void
    {
        $statement = $this->writer()->prepare(
            'INSERT INTO store (key, value, created, updated)'
            . ' VALUES (:key, :value, :now, :now)'
            . ' ON CONFLICT (key) DO UPDATE SET value = excluded.value, updated = excluded.updated',
        );
        $statement->execute([
            ':key' => $key,
            ':value' => $this->encode($key, $value),
            ':now' => gmdate('c'),
        ]);
    }

    /**
     * Insert-if-absent, atomically: false means the key already exists and
     * nothing was written. The uniqueness primitive — "already subscribed?"
     * is not a check-then-insert dance but this single call, with no race
     * window between concurrent POSTs.
     */
    public function add(string $key, mixed $value): bool
    {
        $statement = $this->writer()->prepare(
            'INSERT INTO store (key, value, created, updated)'
            . ' VALUES (:key, :value, :now, :now)'
            . ' ON CONFLICT (key) DO NOTHING',
        );
        $statement->execute([
            ':key' => $key,
            ':value' => $this->encode($key, $value),
            ':now' => gmdate('c'),
        ]);

        return $statement->rowCount() === 1;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $pdo = $this->reader();

        if ($pdo === null) {
            return $default;
        }

        $statement = $pdo->prepare('SELECT value FROM store WHERE key = :key LIMIT 1');
        $statement->execute([':key' => $key]);
        $row = $statement->fetch();

        if (!is_array($row) || !is_string($row['value'] ?? null)) {
            return $default;
        }

        // A stored null is a present value (has() agrees), so it must win
        // over $default — hence row existence decides, not the decoded value.
        return json_decode($row['value'], true);
    }

    public function has(string $key): bool
    {
        $pdo = $this->reader();

        if ($pdo === null) {
            return false;
        }

        $statement = $pdo->prepare('SELECT 1 FROM store WHERE key = :key LIMIT 1');
        $statement->execute([':key' => $key]);

        return $statement->fetch() !== false;
    }

    public function remove(string $key): void
    {
        // A store that was never written has nothing to remove — and a
        // delete must not be the write that creates the file. is_link()
        // as well: a dangling symlink is not "never written", it is a
        // planted link connect() must get the chance to refuse.
        if ($this->pdo === null && !is_file($this->path) && !is_link($this->path)) {
            return;
        }

        // A delete is a write: go through writer() so delete-only
        // maintenance (e.g. a store:remove sweep) also runs the
        // permission hardening, the same as any other write.
        $this->writer()->prepare('DELETE FROM store WHERE key = :key')->execute([':key' => $key]);
    }

    /**
     * Every item whose key starts with $prefix (all items when empty),
     * keyed by full key and ordered by key. Plain string-prefix matching —
     * the ":" separator is a naming convention, not an API concept.
     *
     * Loads the whole namespace into memory: fine for the hundreds-of-rows
     * scale this store targets. Reach for count() when the number is all
     * that's needed.
     *
     * @return Collection<string, mixed>
     */
    public function items(string $prefix = ''): Collection
    {
        $pdo = $this->reader();

        if ($pdo === null) {
            return new Collection();
        }

        [$where, $params] = $this->prefixClause($prefix);
        $statement = $pdo->prepare("SELECT key, value FROM store{$where} ORDER BY key");
        $statement->execute($params);

        $items = [];

        foreach ($statement->fetchAll() as $row) {
            if (
                !is_array($row)
                || !is_string($row['key'] ?? null)
                || !is_string($row['value'] ?? null)
            ) {
                continue;
            }

            $items[$row['key']] = json_decode($row['value'], true);
        }

        return new Collection($items);
    }

    /**
     * How many keys start with $prefix (all keys when empty), without
     * loading any values.
     */
    public function count(string $prefix = ''): int
    {
        $pdo = $this->reader();

        if ($pdo === null) {
            return 0;
        }

        [$where, $params] = $this->prefixClause($prefix);
        $statement = $pdo->prepare("SELECT COUNT(*) FROM store{$where}");
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    /**
     * @param string $key Only for the error message.
     */
    private function encode(string $key, mixed $value): string
    {
        try {
            return json_encode(
                $value,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                sprintf(
                    'Store values must be JSON-encodable; the value for "%s" is not (%s)',
                    $key,
                    $exception->getMessage(),
                ),
                previous: $exception,
            );
        }
    }

    /**
     * WHERE clause + bindings for a key-prefix query. substr() equality
     * rather than LIKE: SQLite's LIKE is ASCII case-insensitive by
     * default, which would let items('email:') also match "Email:…" keys
     * even though key equality is case-sensitive. A substring comparison
     * uses the column's binary collation — case-sensitive, matching the
     * rest of the key contract — and has no wildcards to escape, so the
     * prefix always matches literally.
     *
     * @return array{string, array<string, string>}
     */
    private function prefixClause(string $prefix): array
    {
        if ($prefix === '') {
            return ['', []];
        }

        // SQLite dedupes named parameters, so :prefix binds once.
        return [' WHERE substr(key, 1, length(:prefix)) = :prefix', [':prefix' => $prefix]];
    }

    /**
     * A connection for read paths, or null when there is nothing to read
     * — no file (reads must not create it; lazy creation is write-side
     * only), or a file without the store table yet: a concurrent first
     * write creates the file on connect and the schema an instant later,
     * and an empty pre-created file has no table at all. Both answer
     * "nothing stored" rather than letting a query hit a missing table.
     */
    private function reader(): ?PDO
    {
        if ($this->pdo === null) {
            // is_link() as well: is_file() is false for a dangling
            // symlink, which must not read as "nothing stored" — a
            // planted link should be refused (by connect()) on first
            // touch, not lie dormant until something writes.
            if (!is_file($this->path) && !is_link($this->path)) {
                return null;
            }

            $this->pdo = $this->connect();
        }

        if (!$this->schemaReady) {
            $statement = $this->pdo->query(
                "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'store'",
            );

            if ($statement === false || $statement->fetch() === false) {
                return null;
            }

            $this->schemaReady = true;
        }

        return $this->pdo;
    }

    /**
     * A connection for write paths, creating the directory, file, and
     * schema on first use.
     */
    private function writer(): PDO
    {
        if ($this->pdo === null) {
            $this->ensureDirectory();
            $this->pdo = $this->connect();
        }

        if (!$this->hardened) {
            $this->harden();
            $this->hardened = true;
        }

        if (!$this->schemaReady) {
            // Timestamps are store-maintained bookkeeping (deliberately
            // boring — useful the first time anyone asks "when did this
            // row appear?"); v1 doesn't surface them in the read API.
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS store ('
                . 'key TEXT PRIMARY KEY, value TEXT NOT NULL,'
                . ' created TEXT NOT NULL, updated TEXT NOT NULL)',
            );
            $this->schemaReady = true;
        }

        return $this->pdo;
    }

    /**
     * Make the store file owner-only (0600) before this instance's first
     * write, or refuse the write. PDO creates the file with the umask's
     * default (commonly 0644), and store values are site data — possibly
     * personal, like the email addresses of the motivating form — so
     * other local users on a shared host must not be able to read them.
     * Re-asserted on every instance's first write, not only when the
     * connect created the file: that also covers a file pre-created empty
     * by tooling, a crash between a previous process's connect and chmod,
     * and the reader-connected-first path.
     *
     * chmod fails when this user doesn't own the file — most likely a
     * writable file another local user planted before the first write
     * (the regular-file sibling of the symlink attack connect() refuses).
     * Failing to tighten it must fail the write: proceeding would put
     * site data in a file whose owner can read it. The one exception is a
     * file whose permissions are already owner-only — then the goal is
     * met even though chmod failed (e.g. ACL-managed filesystems), and
     * on the flip side a planted 0600 file isn't openable by this user
     * at all, so it can't reach here.
     */
    private function harden(): void
    {
        // Muted: chmod warns on failure, and the app's error handler
        // would promote that to an exception before the permission
        // check below could decide the failure is actually fine.
        if ($this->muted(fn(): bool => chmod($this->path, 0o600))) {
            return;
        }

        clearstatcache(false, $this->path);
        $permissions = $this->muted(fn(): int|false => fileperms($this->path));

        if ($permissions === false || ($permissions & 0o077) !== 0) {
            throw new RuntimeException(sprintf(
                'Unable to make store file "%s" owner-only; refusing to write site data to it',
                $this->path,
            ));
        }
    }

    private function connect(): PDO
    {
        // Refuse to open through a symlink: on a shared host with a
        // world-writable storage directory, another local user could
        // pre-create store.sqlite as a link and have Garner write site
        // data (possibly personal) to a path they chose — with the
        // owner-only chmod below hardening the wrong file. A store file
        // Garner created is never a symlink, so a link here is always
        // someone else's doing.
        if (is_link($this->path)) {
            throw new RuntimeException(sprintf(
                'Store file "%s" is a symlink; refusing to open it',
                $this->path,
            ));
        }

        return new PDO('sqlite:' . $this->path, options: [
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            // Wait out a concurrent writer's lock instead of failing
            // immediately — overlapping form POSTs are the normal case.
            PDO::ATTR_TIMEOUT => 5,
        ]);
    }

    private function ensureDirectory(): void
    {
        $directory = dirname($this->path);

        if (is_dir($directory)) {
            return;
        }

        // Muted: two simultaneous first writes can both pass the is_dir()
        // check above, and the loser's mkdir() then warns "File exists" —
        // which the app's error handler would promote to an exception
        // before the is_dir() recheck below could conclude that losing
        // the race is fine.
        $created = $this->muted(static fn(): bool => mkdir($directory, 0o700, true));

        if (!$created && !is_dir($directory)) {
            throw new RuntimeException(sprintf(
                'Unable to create storage directory "%s"',
                $directory,
            ));
        }

        // mkdir's mode is filtered through the umask; chmod isn't.
        // Owner-only, same stance as the sessions directory: a
        // world-writable storage directory would let another local user
        // pre-create or replace the store file out from under Garner.
        // Only on creation (the race winner does it): a pre-existing
        // directory keeps whatever permissions the operator chose.
        if ($created) {
            chmod($directory, 0o700);
        }
    }

    /**
     * Run $callback with a warning-swallowing handler swapped in for its
     * duration, rather than the `@` operator: Garner's registered error
     * handler promotes warnings to ErrorException, and `@` only works if
     * every installed handler checks error_reporting() — swapping the
     * handler makes no such assumption. Same helper as FileSessionStore.
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
