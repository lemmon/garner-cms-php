<?php

declare(strict_types=1);

namespace Garner\Content;

use PDO;

final class PathResolver
{
    public function __construct(
        private readonly string $sqlitePath,
        private readonly PageRepository $pageRepository,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function resolve(string $path): ?array
    {
        $pdo = $this->connect();

        if ($pdo === null) {
            return null;
        }

        $statement = $pdo->prepare(<<<'SQL'
            SELECT
                entries.id,
                entries.status,
                entry_paths.path,
                entry_paths.depth
            FROM entry_paths
            INNER JOIN entries ON entries.id = entry_paths.entry_id
            WHERE entry_paths.path = :path
            LIMIT 1
            SQL);
        $statement->execute([
            ':path' => $this->normalizePath($path),
        ]);

        $row = $statement->fetch();
        if (!is_array($row) || !is_string($row['id'] ?? null)) {
            return null;
        }

        $page = $this->pageRepository->find($row['id']);
        if (!is_array($page)) {
            return null;
        }

        $page['resolved_path'] = (string) $row['path'];
        $page['depth'] = (int) $row['depth'];

        return $page;
    }

    public function pathForId(string $id): ?string
    {
        $pdo = $this->connect();

        if ($pdo === null) {
            return null;
        }

        $statement = $pdo->prepare(<<<'SQL'
            SELECT
                path
            FROM entry_paths
            WHERE entry_id = :entry_id
            LIMIT 1
            SQL);
        $statement->execute([
            ':entry_id' => $id,
        ]);

        $row = $statement->fetch();

        return is_array($row) && is_string($row['path'] ?? null) ? $row['path'] : null;
    }

    private function connect(): ?PDO
    {
        if (!is_file($this->sqlitePath)) {
            return null;
        }

        $pdo = new PDO('sqlite:' . $this->sqlitePath);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        return $pdo;
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
