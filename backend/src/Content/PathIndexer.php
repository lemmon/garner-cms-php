<?php

declare(strict_types=1);

namespace Garner\Content;

use Illuminate\Support\Collection;
use PDO;
use RuntimeException;

final class PathIndexer
{
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
        $this->createSchema($pdo);
        $this->writeIndex($pdo, $pages, $paths);

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

    private function createSchema(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS entry_paths');
        $pdo->exec('DROP TABLE IF EXISTS entries');

        $pdo->exec(<<<'SQL'
            CREATE TABLE entries (
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
            SQL);

        $pdo->exec(<<<'SQL'
            CREATE TABLE entry_paths (
                entry_id TEXT PRIMARY KEY,
                path TEXT NOT NULL UNIQUE,
                depth INTEGER NOT NULL
            );
            SQL);
    }

    /**
     * @param Collection<string, array<string, mixed>> $pages
     * @param array<string, string> $paths
     */
    private function writeIndex(PDO $pdo, Collection $pages, array $paths): void
    {
        $pdo->beginTransaction();
        $pdo->exec('DELETE FROM entry_paths');
        $pdo->exec('DELETE FROM entries');

        $entryStatement = $pdo->prepare(<<<'SQL'
            INSERT INTO entries (
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
            SQL);

        $pathStatement = $pdo->prepare(<<<'SQL'
            INSERT INTO entry_paths (
                entry_id,
                path,
                depth
            ) VALUES (
                :entry_id,
                :path,
                :depth
            )
            SQL);

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
