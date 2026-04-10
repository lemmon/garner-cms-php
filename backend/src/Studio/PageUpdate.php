<?php

declare(strict_types=1);

namespace Garner\Studio;

use Garner\Content\PageRepository;
use Garner\Content\PathIndexer;
use Garner\Content\PathResolver;
use Garner\Content\SiteRepository;
use Garner\Site\Site;
use Illuminate\Support\Str;

final class PageUpdate
{
    public function __construct(
        private readonly SiteRepository $siteRepository,
        private readonly PageRepository $pageRepository,
        private readonly PathIndexer $pathIndexer,
        private readonly PathResolver $pathResolver,
    ) {}

    /**
     * @param array<string, mixed> $page Pre-loaded page array from the repository.
     * @return array<string, mixed>
     */
    public function update(array $page, string $title, ?string $slug): array
    {
        $id = is_string($page['id'] ?? null) ? $page['id'] : '';
        $site = $this->buildSite();
        $canEditSlug = !$site->isSystemPage($id);

        if ($canEditSlug) {
            $normalizedSlug = $slug !== null ? Str::slug($slug) : '';

            if ($normalizedSlug === '') {
                throw new \InvalidArgumentException('Slug is required for editable pages');
            }

            $page['slug'] = $normalizedSlug;
        }

        $fields = is_array($page['fields'] ?? null) ? $page['fields'] : [];
        $fields['title'] = Str::squish($title);
        $page['fields'] = $fields;
        $page['updated_at'] = gmdate(DATE_ATOM);

        $this->pageRepository->save($page);
        $this->pathIndexer->rebuild();

        return [
            'ok' => true,
            'page' => [
                'id' => $id,
                'slug' => is_string($page['slug'] ?? null) ? $page['slug'] : null,
                'title' => $fields['title'],
                'path' => $this->pathResolver->pathForId($id),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $page
     */
    public function slugEditableForPage(array $page): bool
    {
        $id = is_string($page['id'] ?? null) ? $page['id'] : '';

        return !$this->buildSite()->isSystemPage($id);
    }

    /**
     * @param array<string, mixed> $page
     */
    public function slugExistsAmongSiblingsForPage(array $page, string $slug): bool
    {
        $id = is_string($page['id'] ?? null) ? $page['id'] : '';
        $normalizedSlug = Str::slug($slug);

        if ($normalizedSlug === '') {
            return false;
        }

        $parentId = is_string($page['parent_id'] ?? null) ? $page['parent_id'] : null;

        foreach ($this->pageRepository->all() as $candidate) {
            $candidateId = is_string($candidate['id'] ?? null) ? $candidate['id'] : '';
            $candidateParentId = is_string($candidate['parent_id'] ?? null)
                ? $candidate['parent_id']
                : null;
            $candidateSlug = is_string($candidate['slug'] ?? null) ? $candidate['slug'] : '';

            if ($candidateId === '' || $candidateId === $id) {
                continue;
            }

            if ($candidateParentId !== $parentId) {
                continue;
            }

            if ($candidateSlug === $normalizedSlug) {
                return true;
            }
        }

        return false;
    }

    public function slugEditable(string $id): bool
    {
        return $this->slugEditableForPage($this->pageRepository->findOrFail($id));
    }

    public function slugExistsAmongSiblings(string $id, string $slug): bool
    {
        return $this->slugExistsAmongSiblingsForPage($this->pageRepository->findOrFail($id), $slug);
    }

    private function buildSite(): Site
    {
        return new Site($this->siteRepository->read());
    }
}
