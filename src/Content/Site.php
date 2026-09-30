<?php

declare(strict_types=1);

namespace Garner\Content;

final class Site
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        private readonly array $meta,
        private readonly ?Pages $pages = null,
        private readonly string $url = '',
    ) {}

    /**
     * The site's base URL (scheme://host), without a trailing slash. Resolved from
     * the `app.url` config when set, otherwise inferred from the request.
     */
    public function url(): string
    {
        return $this->url;
    }

    public function title(): string
    {
        $title = $this->meta['title'] ?? null;

        return is_string($title) && $title !== '' ? $title : 'Garner';
    }

    /**
     * The home page (route "/"), or null when none is defined.
     */
    public function home(): ?Page
    {
        return $this->pages?->home();
    }

    /**
     * Resolve a reference: find any page by its stable id (routable pages only).
     */
    public function findById(string $id): ?Page
    {
        return $this->pages?->findById($id);
    }

    /**
     * Home plus the direct children of "/" (home first when it exists; published
     * only by default — $drafts includes a draft home too). Without a home page — a root endpoint, or no root entry
     * at all — the top-level pages are still the site's top-level sections.
     */
    public function children(bool $drafts = false): PageCollection
    {
        if ($this->pages === null) {
            return new PageCollection();
        }

        return $this->withHome($this->pages->children('/', $drafts), $drafts);
    }

    /**
     * Home plus all descendants of "/" (home first when it exists; published
     * only by default — $drafts includes a draft home too). Like children(), the descendants don't depend on home.
     */
    public function index(bool $drafts = false): PageCollection
    {
        if ($this->pages === null) {
            return new PageCollection();
        }

        return $this->withHome($this->pages->index('/', $drafts), $drafts);
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return $this->meta;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->meta[$key] ?? $default;
    }

    private function withHome(PageCollection $pages, bool $drafts): PageCollection
    {
        $home = $this->pages?->home($drafts);

        return $home === null ? $pages : new PageCollection([$home, ...$pages->all()]);
    }
}
