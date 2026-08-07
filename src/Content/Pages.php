<?php

declare(strict_types=1);

namespace Garner\Content;

use RuntimeException;

/**
 * Read-side repository for traversing the page tree. Backed by the derived
 * route index for lookups and the page loader for hydration. Loaded pages carry
 * a back-reference to this repository so `Page::children()`/`index()` work.
 */
final class Pages
{
    /**
     * Hydrated pages, keyed by directory, reused across calls within this
     * repository's lifetime (normally one request) — Page is read-only, so
     * sharing an instance carries no mutation risk. Avoids re-reading and
     * re-parsing the same directory's entry file when a caller reaches the same
     * page two ways in one request (e.g. a template using both `page.parent` and
     * `page.ancestors`). Cleared whenever $index's generation() moves on (see
     * syncCache()): an explicit ContentIndex::rebuild() call mutates the same
     * index instance this repository was built with, with no other signal to
     * tell this cache its entries no longer match what's on disk.
     *
     * @var array<string, Page>
     */
    private array $cache = [];

    /**
     * Memoized parent()/ancestors() index rows, keyed by normalized path — a
     * breadcrumb template reads `page.parent` several times in one render, and
     * without this each access repeats the same index query. The raw rows are
     * memoized rather than the hydrated results so every caller still gets its
     * own PageCollection (Illuminate collections are mutable); hydration itself
     * is already deduplicated by $cache. Cleared alongside $cache whenever the
     * index generation moves on (see syncCache()).
     *
     * @var array<string, array{path: string, dir: string, hidden: bool}|null>
     */
    private array $parentRows = [];

    /**
     * @var array<string, list<array{path: string, dir: string, hidden: bool}>>
     */
    private array $ancestorRows = [];

    private int $cachedGeneration = -1;

    public function __construct(
        private readonly ContentIndex $index,
        private readonly PageLoader $loader,
    ) {}

    public function find(string $path): ?Page
    {
        $normalized = RoutePath::normalize($path);
        $dir = $this->index->dirForPath($normalized);

        return $dir === null ? null : $this->load($dir, $normalized);
    }

    /**
     * The home page (route "/"), or null when none is defined. A root endpoint
     * still routes via find('/'), but it is not a page — so it is not home, and
     * it never anchors site.children / site.index.
     */
    public function home(): ?Page
    {
        $home = $this->find('/');

        return $home === null || $home->isEndpoint() ? null : $home;
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
        return $this->hydrate($this->index->children(RoutePath::normalize($path), $drafts));
    }

    /**
     * All descendant pages of a route (excluding the route itself; published only by default).
     */
    public function index(string $path, bool $drafts = false): PageCollection
    {
        return $this->hydrate($this->index->descendants(RoutePath::normalize($path), $drafts));
    }

    /**
     * The nearest ancestor page of a route (home for top-level pages), or null
     * for home itself, an endpoint, or an unresolvable route. A stale "locked"
     * index pointing at a directory whose entry file has since been deleted or
     * corrupted is treated the same as "no ancestor" rather than failing the
     * whole render — see ancestors() for why.
     */
    public function parent(string $path): ?Page
    {
        $this->syncCache();
        $normalized = RoutePath::normalize($path);

        if (!array_key_exists($normalized, $this->parentRows)) {
            $this->parentRows[$normalized] = $this->index->parent($normalized);
        }

        $row = $this->parentRows[$normalized];

        return $row === null ? null : $this->loadStructural($row);
    }

    /**
     * The full ancestor chain of a route, root first (home ... nearest
     * parent) — the order a breadcrumb reads left to right. Empty for home,
     * an endpoint, or an unresolvable route.
     *
     * Unlike children()/index(), a hydration failure partway up the chain is
     * skipped rather than propagated: a page's own content can be perfectly
     * valid while a "locked" (production) index still references an ancestor
     * directory whose entry file was since deleted or corrupted — index
     * freshness is a deploy-time contract in that mode, not something a normal
     * request re-checks — and an otherwise-fine page must still render even
     * with a degraded breadcrumb, the same way parentPath() already skips a
     * plain grouping directory rather than breaking the chain over it.
     */
    public function ancestors(string $path): PageCollection
    {
        $this->syncCache();
        $normalized = RoutePath::normalize($path);

        if (!array_key_exists($normalized, $this->ancestorRows)) {
            $this->ancestorRows[$normalized] = $this->index->ancestors($normalized);
        }

        $rows = $this->ancestorRows[$normalized];
        $pages = [];

        foreach ($rows as $row) {
            $page = $this->loadStructural($row);

            if ($page !== null) {
                $pages[] = $page;
            }
        }

        return new PageCollection($pages);
    }

    /**
     * @param list<array{path: string, dir: string, hidden: bool}> $rows
     */
    private function hydrate(array $rows): PageCollection
    {
        $pages = [];

        foreach ($rows as $row) {
            $pages[] = $this->load($row['dir'], $row['path'], $row['hidden']);
        }

        return new PageCollection($pages);
    }

    /**
     * @param array{path: string, dir: string, hidden: bool} $row
     */
    private function loadStructural(array $row): ?Page
    {
        try {
            $page = $this->load($row['dir'], $row['path'], $row['hidden']);
        } catch (RuntimeException) {
            return null;
        }

        // A stale "locked" index can carry an ancestor row built when its
        // directory was still a page (endpoint = 0 at index time), but that
        // directory has since become an endpoint on disk (its +page.json
        // replaced by a +controller.php) without a reindex to catch the change.
        // PageLoader then hydrates it successfully — endpoint: true — rather
        // than throwing, so the failure path above never sees it; reject it
        // here instead, the same way children()/descendants() exclude it in SQL.
        return $page->isEndpoint() ? null : $page;
    }

    private function load(string $dir, string $path, bool $hidden = false): Page
    {
        $this->syncCache();

        return $this->cache[$dir] ??= $this->loader->load($dir, $path, $this, $hidden);
    }

    private function syncCache(): void
    {
        $generation = $this->index->generation();

        if ($generation !== $this->cachedGeneration) {
            $this->cache = [];
            $this->parentRows = [];
            $this->ancestorRows = [];
            $this->cachedGeneration = $generation;
        }
    }
}
