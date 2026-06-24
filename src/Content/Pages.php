<?php

declare(strict_types=1);

namespace Garner\Content;

/**
 * Read-side repository for traversing the page tree. Backed by the derived
 * route index for lookups and the page loader for hydration. Loaded pages carry
 * a back-reference to this repository so `Page::children()`/`index()` work.
 */
final class Pages
{
    public function __construct(
        private readonly ContentIndex $index,
        private readonly PageLoader $loader,
    ) {}

    public function find(string $path): ?Page
    {
        $normalized = $this->normalize($path);
        $dir = $this->index->dirForPath($normalized);

        return $dir === null ? null : $this->loader->load($dir, $normalized, $this);
    }

    public function home(): ?Page
    {
        return $this->find('/');
    }

    /**
     * Find any page by its stable id (routable pages only). Resolution is
     * independent of where the page currently lives, so references survive moves.
     */
    public function findById(string $id): ?Page
    {
        $path = $this->index->pathForId($id);

        return $path === null ? null : $this->find($path);
    }

    /**
     * Direct child pages of a route (published only; pass drafts: true to include drafts).
     */
    public function children(string $path, bool $drafts = false): PageCollection
    {
        return $this->hydrate($this->index->children($this->normalize($path), $drafts));
    }

    /**
     * All descendant pages of a route (excluding the route itself; published only by default).
     */
    public function index(string $path, bool $drafts = false): PageCollection
    {
        return $this->hydrate($this->index->descendants($this->normalize($path), $drafts));
    }

    /**
     * @param list<array{path: string, dir: string}> $rows
     */
    private function hydrate(array $rows): PageCollection
    {
        $pages = [];

        foreach ($rows as $row) {
            $pages[] = $this->loader->load($row['dir'], $row['path'], $this);
        }

        return new PageCollection($pages);
    }

    private function normalize(string $path): string
    {
        $trimmed = trim($path);

        if ($trimmed === '' || $trimmed === '/') {
            return '/';
        }

        return '/' . trim($trimmed, '/');
    }
}
