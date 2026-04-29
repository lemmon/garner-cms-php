<?php

declare(strict_types=1);

namespace Garner\Content;

use Illuminate\Support\Collection;
use PDO;
use RuntimeException;
use Throwable;

final class PathIndexer
{
    private const LIVE_ENTRIES_TABLE = 'entries';
    private const LIVE_PATHS_TABLE = 'entry_paths';
    private const NEXT_ENTRIES_TABLE = 'entries_next';
    private const NEXT_PATHS_TABLE = 'entry_paths_next';

    public function __construct(
        private readonly SiteRepository $siteRepository,
        private readonly PageRepository $pageRepository,
        private readonly string $sqlitePath,
    ) {}

    /**
     * @return array{entry_count: int, path_count: int, index_path: string}
     */
    public function rebuild(): array
    {
        $site = $this->siteRepository->read();
        $homePageId = is_string($site['home_page_id'] ?? null) ? $site['home_page_id'] : null;
        $pages = $this->pageRepository->all()->keyBy(
            static fn(array $page): string => (string) $page['id'],
        );

        $paths = [];
        $pathCache = [];

        foreach ($pages as $pageId => $page) {
            $path = $this->buildPath(
                pageId: (string) $pageId,
                pages: $pages,
                homePageId: $homePageId,
                pathCache: $pathCache,
                stack: [],
            );

            if ($path !== null) {
                $paths[(string) $pageId] = $path;
            }
        }

        $pdo = $this->connect();
        $this->writeReplacementIndex($pdo, $pages, $paths);

        return [
            'entry_count' => $pages->count(),
            'path_count' => count($paths),
            'index_path' => $this->sqlitePath,
        ];
    }

    private function connect(): PDO
    {
        $directory = dirname($this->sqlitePath);

        if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf(
                'Unable to create runtime directory "%s"',
                $directory,
            ));
        }

        $pdo = new PDO('sqlite:' . $this->sqlitePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        return $pdo;
    }

    /**
     * @param Collection<string, array<string, mixed>> $pages
     * @param array<string, string> $paths
     */
    private function writeReplacementIndex(PDO $pdo, Collection $pages, array $paths): void
    {
        $this->dropTables($pdo, self::NEXT_PATHS_TABLE, self::NEXT_ENTRIES_TABLE);
        $this->createSchema($pdo, self::NEXT_ENTRIES_TABLE, self::NEXT_PATHS_TABLE);

        try {
            $this->writeIndex(
                $pdo,
                $pages,
                $paths,
                self::NEXT_ENTRIES_TABLE,
                self::NEXT_PATHS_TABLE,
            );
            $this->swapReplacementTables($pdo);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $this->dropTables($pdo, self::NEXT_PATHS_TABLE, self::NEXT_ENTRIES_TABLE);

            throw $exception;
        }
    }

    private function createSchema(PDO $pdo, string $entriesTable, string $pathsTable): void
    {
        $pdo->exec(sprintf(<<<'SQL'
            CREATE TABLE %s (
                id TEXT PRIMARY KEY,
                kind TEXT NOT NULL,
                parent_id TEXT NULL,
                slug TEXT NULL,
                blueprint TEXT NOT NULL,
                template TEXT NOT NULL,
                status TEXT NULL,
                sort INTEGER NULL,
                updated_at TEXT NOT NULL,
                content_hash TEXT NOT NULL
            );
            SQL, $entriesTable));

        $pdo->exec(sprintf(<<<'SQL'
            CREATE TABLE %s (
                entry_id TEXT PRIMARY KEY,
                path TEXT NOT NULL UNIQUE,
                depth INTEGER NOT NULL
            );
            SQL, $pathsTable));
    }

    /**
     * @param Collection<string, array<string, mixed>> $pages
     * @param array<string, string> $paths
     */
    private function writeIndex(
        PDO $pdo,
        Collection $pages,
        array $paths,
        string $entriesTable,
        string $pathsTable,
    ): void {
        $pdo->beginTransaction();

        $entryStatement = $pdo->prepare(sprintf(<<<'SQL'
            INSERT INTO %s (
                id,
                kind,
                parent_id,
                slug,
                blueprint,
                template,
                status,
                sort,
                updated_at,
                content_hash
            ) VALUES (
                :id,
                :kind,
                :parent_id,
                :slug,
                :blueprint,
                :template,
                :status,
                :sort,
                :updated_at,
                :content_hash
            )
            SQL, $entriesTable));

        $pathStatement = $pdo->prepare(sprintf(<<<'SQL'
            INSERT INTO %s (
                entry_id,
                path,
                depth
            ) VALUES (
                :entry_id,
                :path,
                :depth
            )
            SQL, $pathsTable));

        foreach ($pages as $page) {
            $pageJson = json_encode($page, JSON_UNESCAPED_SLASHES);

            if (!is_string($pageJson)) {
                throw new RuntimeException('Unable to hash page content');
            }

            $entryStatement->execute([
                ':id' => (string) $page['id'],
                ':kind' => (string) ($page['kind'] ?? 'page'),
                ':parent_id' => is_string($page['parent_id'] ?? null) ? $page['parent_id'] : null,
                ':slug' => $this->normalizedSlug($page),
                ':blueprint' => (string) ($page['blueprint'] ?? 'default'),
                ':template' => (string) ($page['template'] ?? 'default'),
                ':status' => is_string($page['status'] ?? null) ? $page['status'] : null,
                ':sort' => is_int($page['sort'] ?? null) ? $page['sort'] : null,
                ':updated_at' => (string) ($page['updated_at'] ?? ''),
                ':content_hash' => sha1($pageJson),
            ]);

            $pageId = (string) $page['id'];

            if (!array_key_exists($pageId, $paths)) {
                continue;
            }

            $path = $paths[$pageId];

            $pathStatement->execute([
                ':entry_id' => $pageId,
                ':path' => $path,
                ':depth' => $this->depthForPath($path),
            ]);
        }

        $pdo->commit();
    }

    private function swapReplacementTables(PDO $pdo): void
    {
        $pdo->beginTransaction();
        $this->dropTables($pdo, self::LIVE_PATHS_TABLE, self::LIVE_ENTRIES_TABLE);
        $pdo->exec(sprintf(
            'ALTER TABLE %s RENAME TO %s',
            self::NEXT_ENTRIES_TABLE,
            self::LIVE_ENTRIES_TABLE,
        ));
        $pdo->exec(sprintf(
            'ALTER TABLE %s RENAME TO %s',
            self::NEXT_PATHS_TABLE,
            self::LIVE_PATHS_TABLE,
        ));
        $pdo->commit();
    }

    private function dropTables(PDO $pdo, string ...$tables): void
    {
        foreach ($tables as $table) {
            $pdo->exec(sprintf('DROP TABLE IF EXISTS %s', $table));
        }
    }

    /**
     * @param Collection<string, array<string, mixed>> $pages
     * @param array<string, string|null> $pathCache
     * @param list<string> $stack
     */
    private function buildPath(
        string $pageId,
        Collection $pages,
        ?string $homePageId,
        array &$pathCache,
        array $stack,
    ): ?string {
        if (array_key_exists($pageId, $pathCache)) {
            return $pathCache[$pageId];
        }

        if (in_array($pageId, $stack, true)) {
            throw new RuntimeException(sprintf(
                'Circular parent reference detected for "%s"',
                $pageId,
            ));
        }

        $page = $pages->get($pageId);

        if (!is_array($page)) {
            return null;
        }

        if ($homePageId !== null && $pageId === $homePageId) {
            return $pathCache[$pageId] = '/';
        }

        $status = is_string($page['status'] ?? null) ? $page['status'] : null;
        if ($status === null || $status === 'draft') {
            return $pathCache[$pageId] = null;
        }

        $parentId = is_string($page['parent_id'] ?? null) ? $page['parent_id'] : null;
        $segment = $this->pathSegment($pageId, $page);

        if ($parentId === null) {
            return $pathCache[$pageId] = '/' . $segment;
        }

        $parentPath = $this->buildPath(
            pageId: $parentId,
            pages: $pages,
            homePageId: $homePageId,
            pathCache: $pathCache,
            stack: [...$stack, $pageId],
        );

        if ($parentPath === null) {
            return $pathCache[$pageId] = null;
        }

        return $pathCache[$pageId] = $parentPath === '/'
            ? '/' . $segment
            : rtrim($parentPath, '/') . '/' . $segment;
    }

    /**
     * @param array<string, mixed> $page
     */
    private function normalizedSlug(array $page): ?string
    {
        $slug = $page['slug'] ?? null;

        if (!is_string($slug)) {
            return null;
        }

        $slug = trim($slug, '/');

        return $slug !== '' ? $slug : null;
    }

    /**
     * @param array<string, mixed> $page
     */
    private function pathSegment(string $pageId, array $page): string
    {
        return $this->normalizedSlug($page) ?? $pageId;
    }

    private function depthForPath(string $path): int
    {
        if ($path === '/') {
            return 0;
        }

        return count(array_filter(explode('/', trim($path, '/'))));
    }
}
