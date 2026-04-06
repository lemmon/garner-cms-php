<?php

declare(strict_types=1);

namespace Garner\Site;

use Illuminate\Support\Collection;

final class Site
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private readonly array $data,
        private readonly ?Pages $pages = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->data;
    }

    public function homePageId(): ?string
    {
        return is_string($this->data['home_page_id'] ?? null) ? $this->data['home_page_id'] : null;
    }

    public function errorPageId(): ?string
    {
        return is_string($this->data['error_page_id'] ?? null)
            ? $this->data['error_page_id']
            : null;
    }

    public function title(): string
    {
        $title = $this->value('title');

        return is_string($title) && $title !== '' ? $title : 'Garner CMS';
    }

    public function home(): ?Page
    {
        $homePageId = $this->homePageId();

        return $homePageId !== null ? $this->page($homePageId) : null;
    }

    public function errorPage(): ?Page
    {
        $errorPageId = $this->errorPageId();

        return $errorPageId !== null ? $this->page($errorPageId) : null;
    }

    public function page(string $id): ?Page
    {
        if ($this->pages === null) {
            return null;
        }

        return $this->pages->find($id);
    }

    /**
     * Returns the editorial site tree root slice:
     * the home page plus its direct children.
     *
     * @return Collection<int, Page>
     */
    public function children(bool $drafts = false): Collection
    {
        $home = $this->home();

        if ($home === null || $home->status() === 'draft' && !$drafts) {
            return new Collection();
        }

        return Collection::make([$home])->concat($home->children($drafts))->values();
    }

    /**
     * Returns the full public content tree:
     * the home page plus all of its descendants.
     *
     * @return Collection<int, Page>
     */
    public function index(bool $drafts = false): Collection
    {
        $home = $this->home();

        if ($home === null || $home->status() === 'draft' && !$drafts) {
            return new Collection();
        }

        return Collection::make([$home])->concat($home->index($drafts))->values();
    }

    /**
     * @return Collection<int, Page>
     */
    public function systemPages(bool $drafts = false): Collection
    {
        $pages = new Collection();
        $errorPage = $this->errorPage();

        if ($errorPage === null) {
            return $pages;
        }

        if ($drafts || $errorPage->status() !== 'draft') {
            $pages->push($errorPage);
        }

        return $pages->values();
    }

    public function value(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }
}
