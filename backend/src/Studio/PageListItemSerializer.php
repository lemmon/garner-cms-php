<?php

declare(strict_types=1);

namespace Garner\Studio;

use Garner\Content\PageRepository;
use Garner\Site\Page;
use Garner\Site\Site;

final class PageListItemSerializer
{
    public function __construct(
        private readonly PageRepository $pageRepository,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function serialize(Page $page, Site $site, Site|Page $source, string $query): array
    {
        $data = $page->data();
        $path = $page->resolvedPath();

        return [
            'id' => $page->id(),
            'parent_id' => $page->parentId(),
            'slug' => $page->slug(),
            'status' => $page->status(),
            'sort' => is_int($data['sort'] ?? null) ? $data['sort'] : null,
            'blueprint' => $page->blueprint(),
            'template' => $page->template(),
            'title' => $page->title(),
            'path' => $path,
            'depth' => $this->absoluteDepth($path),
            'relative_depth' => $this->relativeDepth($page, $source, $query),
            'is_home' => $site->homePageId() === $page->id(),
            'is_error' => $site->errorPageId() === $page->id(),
        ];
    }

    private function absoluteDepth(?string $path): ?int
    {
        if ($path === null) {
            return null;
        }

        if ($path === '/') {
            return 0;
        }

        return count(array_filter(explode('/', trim($path, '/'))));
    }

    private function relativeDepth(Page $page, Site|Page $source, string $query): int
    {
        $normalized = preg_replace('/\s+/', '', strtolower($query));

        if (!is_string($normalized) || str_starts_with($normalized, 'source.system_pages')) {
            return 0;
        }

        if ($source instanceof Site) {
            $homePageId = $source->homePageId();

            if ($homePageId === null || $page->id() === $homePageId) {
                return 0;
            }

            return $this->depthFromAncestor($page, $homePageId) + 1;
        }

        return $this->depthFromAncestor($page, $source->id());
    }

    private function depthFromAncestor(Page $page, string $ancestorId): int
    {
        $depth = 0;
        $currentParentId = $page->parentId();

        while ($currentParentId !== null) {
            if ($currentParentId === $ancestorId) {
                return $depth;
            }

            $parent = $this->pageRepository->find($currentParentId);

            if (!is_array($parent)) {
                break;
            }

            $depth++;
            $currentParentId = is_string($parent['parent_id'] ?? null)
                ? $parent['parent_id']
                : null;
        }

        return max(0, $depth);
    }
}
