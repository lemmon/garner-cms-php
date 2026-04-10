<?php

declare(strict_types=1);

namespace Garner\Site;

use Garner\Content\PageRepository;
use Garner\Content\PathResolver;
use Illuminate\Support\Collection;

final class Pages
{
    public function __construct(
        private readonly PageRepository $pageRepository,
        private readonly PathResolver $pathResolver,
    ) {}

    /**
     * @return Collection<int, Page>
     */
    public function all(): Collection
    {
        return $this->pageRepository
            ->all()
            ->map($this->makePage(...))
            ->values();
    }

    public function find(mixed $id): ?Page
    {
        $page = $this->pageRepository->find($id);

        return is_array($page) ? $this->makePage($page) : null;
    }

    public function findOrFail(mixed $id): Page
    {
        return $this->makePage($this->pageRepository->findOrFail($id));
    }

    /**
     * @return Collection<int, Page>
     */
    public function childrenOf(string $parentId, bool $drafts = false): Collection
    {
        return $this
            ->all()
            ->filter(
                static fn(Page $page): bool => (
                    $page->parentId() === $parentId
                    && ($drafts || $page->status() !== 'draft')
                ),
            )
            ->values();
    }

    /**
     * @return Collection<int, Page>
     */
    public function indexOf(string $parentId, bool $drafts = false): Collection
    {
        $pages = new Collection();

        foreach ($this->childrenOf($parentId, $drafts) as $child) {
            $pages->push($child);

            foreach ($this->indexOf($child->id(), $drafts) as $descendant) {
                $pages->push($descendant);
            }
        }

        return $pages->values();
    }

    /**
     * @return Collection<int, Page>
     */
    public function listed(): Collection
    {
        return $this
            ->all()
            ->filter(static fn(Page $page): bool => $page->status() === 'listed')
            ->values();
    }

    /**
     * @return Collection<int, Page>
     */
    public function unlisted(): Collection
    {
        return $this
            ->all()
            ->filter(static fn(Page $page): bool => $page->status() === 'unlisted')
            ->values();
    }

    /**
     * @param array<string, mixed> $page
     */
    private function makePage(array $page): Page
    {
        if (!array_key_exists('resolved_path', $page)) {
            $page['resolved_path'] = $this->pathResolver->pathForId((string) $page['id']);
        }

        return new Page($page, $this);
    }
}
