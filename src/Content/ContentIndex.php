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
 *     sort: int
 * }
 */
final class ContentIndex
{
    private bool $fresh = false;

    public function __construct(
        private readonly string $contentPath,
        private readonly string $sqlitePath,
        private readonly string $mode = 'scan',
    ) {}

    public function dirForPath(string $path): ?string
    {
        $this->ensureFresh();

        $pdo = $this->reader();

        if ($pdo === null) {
            return null;
        }

        $statement = $pdo->prepare(
            'SELECT dir FROM pages WHERE path = :path AND draft = 0 LIMIT 1',
        );
        $statement->execute([':path' => $this->normalizePath($path)]);
        $row = $statement->fetch();

        return is_array($row) && is_string($row['dir'] ?? null) ? $row['dir'] : null;
    }

    /**
     * Resolve a page id to its current route path. Published pages only, so a
     * reference to a draft or missing page resolves to null.
     */
    public function pathForId(string $id): ?string
    {
        $this->ensureFresh();

        $pdo = $this->reader();

        if ($pdo === null) {
            return null;
        }

        $statement = $pdo->prepare('SELECT path FROM pages WHERE id = :id AND draft = 0 LIMIT 1');
        $statement->execute([':id' => $id]);
        $row = $statement->fetch();

        return is_array($row) && is_string($row['path'] ?? null) ? $row['path'] : null;
    }

    /**
     * Direct child pages of a route, ordered by sort then route path. Drafts are
     * excluded unless $drafts is true.
     *
     * @return list<array{path: string, dir: string}>
     */
    public function children(string $path, bool $drafts = false): array
    {
        $draftClause = $drafts ? '' : ' AND draft = 0';

        return $this->select(
            "SELECT path, dir FROM pages WHERE parent_path = :path{$draftClause} ORDER BY sort, path",
            [':path' => $this->normalizePath($path)],
        );
    }

    /**
     * All descendant pages of a route (excluding the route itself), ordered by
     * sort then path. Drafts are excluded unless $drafts is true.
     *
     * @return list<array{path: string, dir: string}>
     */
    public function descendants(string $path, bool $drafts = false): array
    {
        $normalized = $this->normalizePath($path);
        $draftClause = $drafts ? '' : ' AND draft = 0';

        if ($normalized === '/') {
            return $this->select(
                "SELECT path, dir FROM pages WHERE path != :root{$draftClause} ORDER BY sort, path",
                [':root' => '/'],
            );
        }

        return $this->select(
            "SELECT path, dir FROM pages WHERE path LIKE :prefix ESCAPE '\\'{$draftClause}"
            . ' ORDER BY sort, path',
            [':prefix' => $this->escapeLike($normalized) . '/%'],
        );
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

        if ($this->mode === 'locked') {
            if (!is_file($this->sqlitePath)) {
                $this->rebuild();
            }

            return;
        }

        $pages = $this->scan();
        $fingerprint = $this->fingerprint($pages);

        if (is_file($this->sqlitePath) && $this->storedFingerprint() === $fingerprint) {
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

        if ($entry !== null) {
            $meta = FormatParser::parse($entry);

            if (!is_array($meta)) {
                throw new InvalidEntryException(sprintf(
                    'Entry "%s" must decode to an object',
                    $entry,
                ));
            }

            PageMeta::assertValid($meta, $entry);

            $path = $this->routePath($dir);
            $pages[] = [
                'path' => $path,
                'dir' => $dir,
                'id' => PageMeta::resolveId($meta, $dir),
                'template' => PageMeta::template($meta),
                'title' => is_string($meta['title'] ?? null) ? $meta['title'] : null,
                'created' => is_string($meta['created'] ?? null) ? $meta['created'] : null,
                'depth' => $this->depth($path),
                'mtime' => (int) filemtime($entry),
                'draft' => PageMeta::isDraft($meta),
                'sort' => PageMeta::sort($meta),
            ];
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
                $pathSet[$page['path']] = true;
            }

            $insert = $pdo->prepare(
                'INSERT INTO pages'
                . ' (path, dir, id, template, title, created, depth, parent_path, draft, sort)'
                . ' VALUES'
                . ' (:path, :dir, :id, :template, :title, :created, :depth, :parent_path, :draft,'
                . ' :sort)',
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
                    ':parent_path' => $this->parentPath($page['path'], $pathSet),
                    ':draft' => $page['draft'] ? 1 : 0,
                    ':sort' => $page['sort'],
                ]);
            }

            $meta = $pdo->prepare('INSERT INTO meta (key, value) VALUES (:key, :value)');
            $meta->execute([':key' => 'fingerprint', ':value' => $fingerprint]);
            $meta->execute([':key' => 'built_at', ':value' => gmdate('c')]);

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
        $this->swap($tmp);
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
            . ' draft INTEGER NOT NULL DEFAULT 0, sort INTEGER NOT NULL DEFAULT 0)',
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
     * @return list<array{path: string, dir: string}>
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
            if (
                !is_array($row)
                || !is_string($row['path'] ?? null)
                || !is_string($row['dir'] ?? null)
            ) {
                continue;
            }

            $rows[] = ['path' => $row['path'], 'dir' => $row['dir']];
        }

        return $rows;
    }

    private function reader(): ?PDO
    {
        if (!is_file($this->sqlitePath)) {
            return null;
        }

        $pdo = new PDO('sqlite:' . $this->sqlitePath);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        return $pdo;
    }

    private function storedFingerprint(): ?string
    {
        $pdo = $this->reader();

        if ($pdo === null) {
            return null;
        }

        try {
            $statement = $pdo->query("SELECT value FROM meta WHERE key = 'fingerprint' LIMIT 1");

            if ($statement === false) {
                return null;
            }

            $row = $statement->fetch();

            return is_array($row) && is_string($row['value'] ?? null) ? $row['value'] : null;
        } catch (Throwable) {
            return null;
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

    private function normalizePath(string $path): string
    {
        $trimmed = trim($path);

        if ($trimmed === '' || $trimmed === '/') {
            return '/';
        }

        return '/' . trim($trimmed, '/');
    }
}
