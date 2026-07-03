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
     * Home plus its direct children (home is forced first; published only by default).
     */
    public function children(bool $drafts = false): PageCollection
    {
        $home = $this->pages?->home();

        if ($home === null) {
            return new PageCollection();
        }

        return new PageCollection([$home, ...$home->children($drafts)->all()]);
    }

    /**
     * Home plus all descendants (published only by default).
     */
    public function index(bool $drafts = false): PageCollection
    {
        if ($this->pages === null) {
            return new PageCollection();
        }

        $home = $this->pages->home();

        if ($home === null) {
            return new PageCollection();
        }

        return new PageCollection([$home, ...$this->pages->index('/', $drafts)->all()]);
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
}
