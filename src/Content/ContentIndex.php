<?php

declare(strict_types=1);

namespace Garner\Content;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Derived SQLite index mapping route paths to page directories. Content on the
 * filesystem is canonical; this index is a rebuildable cache.
 *
 * Freshness policy mirrors Twig's auto_reload:
 * - "scan": rebuild when the content tree changes (development default)
 * - "locked": trust the existing index, build once if missing (production)
 *
 * @phpstan-type PageRow array{
 *     path: string,
 *     dir: string,
 *     id: string,
 *     template: string|null,
 *     title: string|null,
 *     created: string|null,
 *     depth: int,
 *     mtime: int,
 *     draft: bool,
 *     sort: int,
 *     endpoint: bool
 * }
 */
final class ContentIndex
{
    private const CONTROLLER_FILE = '+controller.php';

    /**
     * Bump whenever the SQLite schema changes (new/removed/renamed columns or
     * tables). An index built under a different version is treated as stale and
     * rebuilt regardless of the content fingerprint, so engine upgrades self-heal
     * instead of surfacing as a "no such column" 500. See docs/index-freshness.md.
     */
    private const SCHEMA_VERSION = 2;

    /**
     * Upper bound on how many levels ancestors() will walk up parent_path before
     * giving up. Real trees never come close; this exists solely so a hand-edited
     * or corrupted index with a parent_path cycle can't hang a request in an
     * unbounded recursive query.
     */
    private const MAX_ANCESTOR_DEPTH = 1000;

    private bool $fresh = false;

    /**
     * Reused across every read within one instance's lifetime (normally one
     * request) instead of opening a fresh SQLite connection per call — safe
     * because write() clears it whenever it swaps in a new index file, so a
     * post-rebuild read never sees a connection still pinned to the old file.
     *
     * Known limitation: this pinning and $generation below are in-process,
     * per-instance state. A rebuild by *another* process (e.g. `garner reindex`
     * against a running "locked" site) swaps the file on disk, but an existing
     * instance keeps reading the old, now-unlinked inode until discarded. The
     * shipped boot constructs a fresh Application per request, bounding that to
     * the request already in flight (deliberate — one request never sees two
     * index states); a long-lived embedder must likewise construct a fresh
     * instance per unit of work. See docs/index-freshness.md.
     */
    private ?PDO $readerConnection = null;

    /**
     * Bumped every time write() successfully swaps in a new index file — via an
     * automatic freshness check or an explicit rebuild() call. Pages compares
     * this against the generation it last saw to know when its own hydrated-page
     * cache needs to be dropped, since it otherwise gets no notification when a
     * ContentIndex it holds a reference to rewrites itself out from under it.
     */
    private int $generation = 0;

    public function __construct(
        private readonly string $contentPath,
        private readonly string $sqlitePath,
        private readonly string $mode = 'scan',
    ) {}

    /**
     * Resolve a route path to its page directory. `hidden` covers both a page's
     * own `draft` flag and inheriting one from an ancestor (see write()), so a
     * published page nested under a draft directory resolves to null here too,
     * not just the draft directory itself.
     */
    public function dirForPath(string $path): ?string
    {
        $this->ensureFresh();

        $pdo = $this->reader();

        if ($pdo === null) {
            return null;
        }

        $statement = $pdo->prepare(
            'SELECT dir FROM pages WHERE path = :path AND hidden = 0 LIMIT 1',
        );
        $statement->execute([':path' => RoutePath::normalize($path)]);
        $row = $statement->fetch();

        return $this->hasStringFields($row, 'dir') ? $row['dir'] : null;
    }

    /**
     * Resolve a route path to its {path, dir, hidden} row regardless of hidden
     * state. dirForPath() deliberately excludes hidden pages for the lookup
     * every public request takes; this is the preview flow's primitive
     * instead — it lets a caller that has already verified a page-specific
     * credential (see Page::draftPreview()) still resolve and load a draft.
     *
     * @return array{path: string, dir: string, hidden: bool}|null
     */
    public function rowForPath(string $path): ?array
    {
        $this->ensureFresh();

        $pdo = $this->reader();

        if ($pdo === null) {
            return null;
        }

        $statement = $pdo->prepare(
            'SELECT path, dir, hidden FROM pages WHERE path = :path LIMIT 1',
        );
        $statement->execute([':path' => RoutePath::normalize($path)]);

        return $this->normalizeRow($statement->fetch());
    }

    /**
     * Resolve a page id to its current route path. Visible pages only, so a
     * reference to a hidden (draft, or beneath a draft ancestor) or missing page
     * resolves to null.
     */
    public function pathForId(string $id): ?string
    {
        $this->ensureFresh();

        $pdo = $this->reader();

        if ($pdo === null) {
            return null;
        }

        $statement = $pdo->prepare(
            'SELECT path FROM pages WHERE id = :id AND hidden = 0 AND endpoint = 0 LIMIT 1',
        );
        $statement->execute([':id' => $id]);
        $row = $statement->fetch();

        return $this->hasStringFields($row, 'path') ? $row['path'] : null;
    }

    /**
     * Direct child pages of a route, ordered by sort then route path. Hidden pages
     * — drafts, and pages nested under a draft ancestor — are excluded unless
     * $drafts is true.
     *
     * @return list<array{path: string, dir: string, hidden: bool}>
     */
    public function children(string $path, bool $drafts = false): array
    {
        return $this->select(
            'SELECT path, dir, hidden FROM pages WHERE parent_path = :path AND endpoint = 0'
            . $this->hiddenClause($drafts)
            . ' ORDER BY sort, path',
            [':path' => RoutePath::normalize($path)],
        );
    }

    /**
     * All descendant pages of a route (excluding the route itself), ordered by
     * sort then path. Hidden pages — drafts, and pages nested under a draft
     * ancestor — are excluded unless $drafts is true.
     *
     * @return list<array{path: string, dir: string, hidden: bool}>
     */
    public function descendants(string $path, bool $drafts = false): array
    {
        $normalized = RoutePath::normalize($path);

        if ($normalized === '/') {
            return $this->select(
                'SELECT path, dir, hidden FROM pages WHERE path != :root AND endpoint = 0'
                . $this->hiddenClause($drafts)
                . ' ORDER BY sort, path',
                [':root' => '/'],
            );
        }

        return $this->select(
            "SELECT path, dir, hidden FROM pages WHERE path LIKE :prefix ESCAPE '\\' AND endpoint = 0"
            . $this->hiddenClause($drafts)
            . ' ORDER BY sort, path',
            [':prefix' => $this->escapeLike($normalized) . '/%'],
        );
    }

    /**
     * SQL fragment excluding hidden pages, unless $drafts opts back in. Shared by
     * children() and descendants() so the two conditions can't drift apart.
     */
    private function hiddenClause(bool $drafts): string
    {
        return $drafts ? '' : ' AND hidden = 0';
    }

    /**
     * The nearest ancestor page of a route (home for top-level pages), or null
     * for home itself, an endpoint, or an unresolvable route — the stored
     * `parent_path` already skips non-page directories (see parentPath()), so
     * this never needs to walk the filesystem tree itself. Hidden state is not
     * filtered: the parent relationship is structural, independent of
     * visibility, so an ancestor that happens to be hidden is still returned,
     * with the hydrated page's own draft/hidden state left for the caller to
     * act on (e.g. deciding whether to render it as a link).
     *
     * @return array{path: string, dir: string, hidden: bool}|null
     */
    public function parent(string $path): ?array
    {
        $this->ensureFresh();

        $pdo = $this->reader();

        if ($pdo === null) {
            return null;
        }

        $statement = $pdo->prepare(
            'SELECT p.path AS path, p.dir AS dir, p.hidden AS hidden FROM pages c'
            . ' JOIN pages p ON p.path = c.parent_path'
            . ' WHERE c.path = :path AND c.endpoint = 0 AND p.endpoint = 0',
        );
        $statement->execute([':path' => RoutePath::normalize($path)]);

        return $this->normalizeRow($statement->fetch());
    }

    /**
     * The full ancestor chain of a route, root first (home ... nearest
     * parent) — the order a breadcrumb reads left to right. Empty for home, an
     * endpoint, or an unresolvable route. Hidden state is not filtered, for the
     * same reason as parent(). Walks parent_path in a single bounded recursive
     * query rather than calling parent() in a loop, so the whole chain is read
     * from one connection (immune to a concurrent index rebuild swapping the
     * file out mid-walk) instead of one connection and one query per level.
     * Guards against a corrupted `parent_path` cycle two ways: `visited` tracks
     * every path already walked so the recursive step can refuse to revisit one
     * (the real fix — a cycle simply stops where it closes, no duplicates), and
     * MAX_ANCESTOR_DEPTH remains as a hard backstop.
     *
     * @return list<array{path: string, dir: string, hidden: bool}>
     */
    public function ancestors(string $path): array
    {
        $this->ensureFresh();

        $pdo = $this->reader();

        if ($pdo === null) {
            return [];
        }

        // self::MAX_ANCESTOR_DEPTH is inlined as a literal rather than bound: PDO's
        // array-based execute() binds every value as PDO::PARAM_STR, and SQLite's
        // cross-type comparison rules mean an INTEGER chain.depth compared against
        // a TEXT-bound parameter never becomes false — the guard would silently
        // never fire, turning a cyclic parent_path into a genuine infinite loop
        // instead of a bounded one. It's a private int constant, never derived
        // from request input, so inlining it carries no injection risk.
        //
        // Visited entries are recorded as '|' || hex(path) || '|'. Route paths come
        // from directory names, which the filesystem places no restriction on —
        // unlike RoutePath's own CLI-facing validation — so a path can contain a
        // literal '|', and escaping it (e.g. as '\|') is not enough: the escaped
        // form still contains a '|' byte, so instr() can find a marker spanning an
        // entry boundary ('...q\|/p|' contains '|/p|', misreading a real, never-
        // visited '/p' ancestor as already seen). hex() output is pure [0-9A-F],
        // so the delimiter can never occur inside an entry and a marker can only
        // ever match a whole one.
        $statement = $pdo->prepare(
            'WITH RECURSIVE chain(path, dir, hidden, parent_path, depth, visited) AS ('
            . " SELECT path, dir, hidden, parent_path, 0, '|' || hex(path) || '|' FROM pages"
            . ' WHERE path = :start AND endpoint = 0'
            . ' UNION ALL'
            . ' SELECT p.path, p.dir, p.hidden, p.parent_path, chain.depth + 1,'
            . " chain.visited || hex(p.path) || '|'"
            . ' FROM pages p JOIN chain ON p.path = chain.parent_path'
            . ' WHERE p.endpoint = 0 AND chain.depth < '
            . self::MAX_ANCESTOR_DEPTH
            . " AND instr(chain.visited, '|' || hex(p.path) || '|') = 0"
            . ') SELECT path, dir, hidden FROM chain WHERE path != :self ORDER BY depth DESC',
        );
        $normalized = RoutePath::normalize($path);
        $statement->execute([':start' => $normalized, ':self' => $normalized]);

        $rows = [];

        foreach ($statement->fetchAll() as $row) {
            $normalizedRow = $this->normalizeRow($row);

            if ($normalizedRow !== null) {
                $rows[] = $normalizedRow;
            }
        }

        return $rows;
    }

    /**
     * Monotonic counter bumped every time the index is (re)written. See the
     * $generation property for why Pages needs this.
     */
    public function generation(): int
    {
        return $this->generation;
    }

    /**
     * @return array{count: int, index_path: string}
     */
    public function rebuild(): array
    {
        $pages = $this->scan();
        $this->write($pages, $this->fingerprint($pages));

        return ['count' => count($pages), 'index_path' => $this->sqlitePath];
    }

    private function ensureFresh(): void
    {
        if ($this->fresh) {
            return;
        }

        $this->fresh = true;
        $meta = $this->readMeta();
        $schemaStale = $meta['schema_version'] !== self::SCHEMA_VERSION;

        if ($this->mode === 'locked') {
            if ($schemaStale) {
                $this->rebuild();
            }

            return;
        }

        $pages = $this->scan();
        $fingerprint = $this->fingerprint($pages);

        if (!$schemaStale && $meta['fingerprint'] === $fingerprint) {
            return;
        }

        $this->write($pages, $fingerprint);
    }

    /**
     * @return list<PageRow>
     */
    private function scan(): array
    {
        $pages = [];

        if (is_dir($this->contentPath)) {
            $this->collect($this->contentPath, $pages);
        }

        return $pages;
    }

    /**
     * @param list<PageRow> $pages
     */
    private function collect(string $dir, array &$pages): void
    {
        $entry = EntryFile::find($dir);
        $isEndpoint = $entry === null && is_file($dir . '/' . self::CONTROLLER_FILE);

        if ($entry !== null || $isEndpoint) {
            $pages[] = $this->pageRow($dir, $entry, $isEndpoint);
        }

        $names = scandir($dir);

        if ($names === false) {
            return;
        }

        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $child = $dir . '/' . $name;

            if (is_dir($child)) {
                $this->collect($child, $pages);
            }
        }
    }

    /**
     * Build the index row for a routable directory. A directory with an entry file
     * is a content page; one with only a +controller.php is a route endpoint —
     * routable and dispatchable, but carrying no metadata and kept out of the tree.
     *
     * @return PageRow
     */
    private function pageRow(string $dir, ?string $entry, bool $isEndpoint): array
    {
        $meta = [];
        $mtimeSource = $dir . '/' . self::CONTROLLER_FILE;

        if ($entry !== null) {
            $parsed = FormatParser::parse($entry);

            if (!is_array($parsed)) {
                throw new InvalidEntryException(sprintf(
                    'Entry "%s" must decode to an object',
                    $entry,
                ));
            }

            PageMeta::assertValid($parsed, $entry);
            $meta = $parsed;
            $mtimeSource = $entry;
        }

        $path = $this->routePath($dir);

        return [
            'path' => $path,
            'dir' => $dir,
            'id' => PageMeta::resolveId($meta, $dir),
            'template' => PageMeta::template($meta),
            'title' => is_string($meta['title'] ?? null) ? $meta['title'] : null,
            'created' => is_string($meta['created'] ?? null) ? $meta['created'] : null,
            'depth' => $this->depth($path),
            'mtime' => (int) filemtime($mtimeSource),
            'draft' => PageMeta::isDraft($meta),
            'sort' => PageMeta::sort($meta),
            'endpoint' => $isEndpoint,
        ];
    }

    private function routePath(string $dir): string
    {
        $relative = trim(substr($dir, strlen($this->contentPath)), '/');

        return $relative === '' ? '/' : '/' . $relative;
    }

    private function depth(string $path): int
    {
        if ($path === '/') {
            return 0;
        }

        return count(array_filter(
            explode('/', trim($path, '/')),
            static fn(string $segment): bool => $segment !== '',
        ));
    }

    /**
     * Nearest ancestor route that is itself a page (home for top-level pages).
     *
     * @param array<string, true> $pathSet
     */
    private function parentPath(string $path, array $pathSet): ?string
    {
        if ($path === '/') {
            return null;
        }

        $segments = explode('/', trim($path, '/'));
        array_pop($segments);

        while ($segments !== []) {
            $candidate = '/' . implode('/', $segments);

            if (array_key_exists($candidate, $pathSet)) {
                return $candidate;
            }

            array_pop($segments);
        }

        return array_key_exists('/', $pathSet) ? '/' : null;
    }

    /**
     * @param list<PageRow> $pages
     */
    private function fingerprint(array $pages): string
    {
        $parts = [];

        foreach ($pages as $page) {
            $parts[] = $page['dir'] . ':' . $page['mtime'];
        }

        sort($parts);

        return sha1(implode('|', $parts));
    }

    /**
     * @param list<PageRow> $pages
     */
    private function write(array $pages, string $fingerprint): void
    {
        $this->assertUniqueIds($pages);
        $this->ensureRuntimeDirectory();

        $tmp = $this->sqlitePath . '.' . getmypid() . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (is_file($tmp)) {
            unlink($tmp);
        }

        $pdo = new PDO('sqlite:' . $tmp);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        try {
            $this->createSchema($pdo);
            $pdo->beginTransaction();

            $pathSet = [];

            foreach ($pages as $page) {
                // Endpoints are routable but not part of the page tree, so they never
                // serve as a parent when resolving parent_path.
                if ($page['endpoint']) {
                    continue;
                }

                $pathSet[$page['path']] = true;
            }

            $parentPaths = [];

            foreach ($pages as $page) {
                $parentPaths[$page['path']] = $this->parentPath($page['path'], $pathSet);
            }

            // Draft visibility cascades down the tree: a page nested under a draft
            // ancestor is just as unpublished as the ancestor, even when its own
            // `draft` is false. Resolved shallowest-first by depth — not by scan
            // order — so every ancestor's hidden state is guaranteed already known
            // by the time a descendant needs it: an ancestor's route path is always
            // a strict prefix of its descendant's, so it always has a smaller depth,
            // regardless of how collect() happened to enumerate directories.
            $hiddenByPath = [];
            $byDepth = $pages;
            usort($byDepth, static fn(array $a, array $b): int => $a['depth'] <=> $b['depth']);

            foreach ($byDepth as $page) {
                $parentPath = $parentPaths[$page['path']];
                $hiddenByPath[$page['path']] =
                    $page['draft'] || $parentPath !== null && $hiddenByPath[$parentPath];
            }

            $insert = $pdo->prepare(
                'INSERT INTO pages'
                . ' (path, dir, id, template, title, created, depth, parent_path, draft, hidden,'
                . ' sort, endpoint)'
                . ' VALUES'
                . ' (:path, :dir, :id, :template, :title, :created, :depth, :parent_path, :draft,'
                . ' :hidden, :sort, :endpoint)',
            );

            foreach ($pages as $page) {
                $insert->execute([
                    ':path' => $page['path'],
                    ':dir' => $page['dir'],
                    ':id' => $page['id'],
                    ':template' => $page['template'],
                    ':title' => $page['title'],
                    ':created' => $page['created'],
                    ':depth' => $page['depth'],
                    ':parent_path' => $parentPaths[$page['path']],
                    ':draft' => $page['draft'] ? 1 : 0,
                    ':hidden' => $hiddenByPath[$page['path']] ? 1 : 0,
                    ':sort' => $page['sort'],
                    ':endpoint' => $page['endpoint'] ? 1 : 0,
                ]);
            }

            $meta = $pdo->prepare('INSERT INTO meta (key, value) VALUES (:key, :value)');
            $meta->execute([':key' => 'fingerprint', ':value' => $fingerprint]);
            $meta->execute([':key' => 'built_at', ':value' => gmdate('c')]);
            $meta->execute([':key' => 'schema_version', ':value' => (string) self::SCHEMA_VERSION]);

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            unset($pdo);

            if (is_file($tmp)) {
                unlink($tmp);
            }

            throw $exception;
        }

        unset($pdo);

        // Drop any connection cached by an earlier readMeta()/reader() call in this
        // same request (e.g. the freshness check that decided to rebuild) before
        // swapping the file it's pinned to: on Windows, SQLite's file locking
        // would otherwise make the rename() inside swap() fail while that handle
        // is still open on the destination path.
        $this->readerConnection = null;
        $this->swap($tmp);
        ++$this->generation;
    }

    /**
     * @param list<PageRow> $pages
     */
    private function assertUniqueIds(array $pages): void
    {
        $seen = [];

        foreach ($pages as $page) {
            $id = $page['id'];

            if (array_key_exists($id, $seen)) {
                throw new InvalidEntryException(sprintf(
                    'Duplicate page id "%s" in "%s" (already used by "%s")',
                    $id,
                    $page['dir'],
                    $seen[$id],
                ));
            }

            $seen[$id] = $page['dir'];
        }
    }

    private function createSchema(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE pages ('
            . 'path TEXT PRIMARY KEY, dir TEXT NOT NULL, id TEXT NOT NULL, template TEXT NULL,'
            . ' title TEXT NULL, created TEXT NULL, depth INTEGER NOT NULL, parent_path TEXT NULL,'
            . ' draft INTEGER NOT NULL DEFAULT 0, hidden INTEGER NOT NULL DEFAULT 0,'
            . ' sort INTEGER NOT NULL DEFAULT 0, endpoint INTEGER NOT NULL DEFAULT 0)',
        );
        $pdo->exec('CREATE UNIQUE INDEX pages_id ON pages (id)');
        $pdo->exec('CREATE TABLE meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
    }

    private function swap(string $tmp): void
    {
        // rename() atomically replaces the live index on POSIX filesystems.
        if (rename($tmp, $this->sqlitePath)) {
            return;
        }

        if (is_file($tmp)) {
            unlink($tmp);
        }

        throw new RuntimeException(sprintf('Unable to write index "%s"', $this->sqlitePath));
    }

    private function ensureRuntimeDirectory(): void
    {
        $directory = dirname($this->sqlitePath);

        if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf(
                'Unable to create runtime directory "%s"',
                $directory,
            ));
        }
    }

    /**
     * @param array<string, string> $params
     * @return list<array{path: string, dir: string, hidden: bool}>
     */
    private function select(string $sql, array $params): array
    {
        $this->ensureFresh();

        $pdo = $this->reader();

        if ($pdo === null) {
            return [];
        }

        $statement = $pdo->prepare($sql);
        $statement->execute($params);

        $rows = [];

        foreach ($statement->fetchAll() as $row) {
            $normalized = $this->normalizeRow($row);

            if ($normalized !== null) {
                $rows[] = $normalized;
            }
        }

        return $rows;
    }

    /**
     * Validate and narrow a fetched row to the {path, dir, hidden} shape every
     * read method returns, shared by select(), parent(), and ancestors().
     *
     * @return array{path: string, dir: string, hidden: bool}|null
     */
    private function normalizeRow(mixed $row): ?array
    {
        if (!$this->hasStringFields($row, 'path', 'dir')) {
            return null;
        }

        return [
            'path' => $row['path'],
            'dir' => $row['dir'],
            'hidden' => (bool) ($row['hidden'] ?? false),
        ];
    }

    /**
     * Whether a fetched row is an array with a string value for every given key
     * — shared by every fetched-row consumer (dirForPath(), pathForId(),
     * normalizeRow(), readMeta()) so all reads apply the same "trust nothing
     * from a hand-edited or corrupted index" rule in one place.
     */
    private function hasStringFields(mixed $row, string ...$keys): bool
    {
        if (!is_array($row)) {
            return false;
        }

        foreach ($keys as $key) {
            if (!is_string($row[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function reader(): ?PDO
    {
        if ($this->readerConnection !== null) {
            return $this->readerConnection;
        }

        if (!is_file($this->sqlitePath)) {
            return null;
        }

        $pdo = new PDO('sqlite:' . $this->sqlitePath);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        return $this->readerConnection = $pdo;
    }

    /**
     * Reads the index's own bookkeeping (content fingerprint + schema version) in
     * a single query. Missing file/table/keys all read as null, which naturally
     * compares unequal to any real fingerprint or the current SCHEMA_VERSION —
     * so an index with no meta at all is just another flavor of "stale".
     *
     * @return array{fingerprint: ?string, schema_version: ?int}
     */
    private function readMeta(): array
    {
        $empty = ['fingerprint' => null, 'schema_version' => null];
        $pdo = $this->reader();

        if ($pdo === null) {
            return $empty;
        }

        try {
            $statement = $pdo->query('SELECT key, value FROM meta');

            if ($statement === false) {
                return $empty;
            }

            $values = [];

            foreach ($statement->fetchAll() as $row) {
                if (!$this->hasStringFields($row, 'key', 'value')) {
                    continue;
                }

                $values[$row['key']] = $row['value'];
            }

            $schemaVersion = $values['schema_version'] ?? null;

            return [
                'fingerprint' => $values['fingerprint'] ?? null,
                'schema_version' => $schemaVersion !== null ? (int) $schemaVersion : null,
            ];
        } catch (Throwable) {
            return $empty;
        }
    }

    /**
     * Escape SQL LIKE wildcards so a route path is matched literally. The backslash
     * is added first so the escapes we introduce are not themselves re-escaped, and
     * it is paired with an `ESCAPE '\'` clause on the query.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
