<?php

declare(strict_types=1);

namespace Garner\Studio;

use Garner\Content\PageRepository;
use Garner\Content\PathIndexer;
use Garner\Content\PathResolver;
use Garner\Content\SiteRepository;
use Garner\Site\Site;
use Garner\Support\Slug;

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
     * @param array<string, string> $validated Already-validated payload (title, slug, and/or blueprint fields).
     * @return array<string, mixed>
     */
    public function update(array $page, array $validated): array
    {
        $id = (string) $page['id'];
        $fields = is_array($page['fields'] ?? null) ? $page['fields'] : [];
        $slugChanged = false;

        if (array_key_exists('slug', $validated)) {
            $page['slug'] = $validated['slug'];
            $slugChanged = true;
        }

        foreach ($validated as $name => $value) {
            if ($name === 'slug') {
                continue;
            }
            $fields[$name] = $value;
        }

        $page['fields'] = $fields;
        $page['updated_at'] = gmdate(DATE_ATOM);

        $this->pageRepository->save($page);

        if ($slugChanged) {
            $this->pathIndexer->rebuild();
        }

        return [
            'ok' => true,
            'page' => [
                'id' => $id,
                'slug' => is_string($page['slug'] ?? null) ? $page['slug'] : null,
                'title' => $fields['title'] ?? null,
                'fields' => $fields,
                'path' => $this->pathResolver->pathForId($id),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $page
     */
    public function slugEditableForPage(array $page): bool
    {
        $id = (string) $page['id'];

        return !$this->buildSite()->isSystemPage($id);
    }

    /**
     * @param array<string, mixed> $page
     */
    public function slugExistsAmongSiblingsForPage(array $page, string $slug): bool
    {
        $id = (string) $page['id'];
        $parentId = is_string($page['parent_id'] ?? null) ? $page['parent_id'] : null;
        $normalizedSlug = Slug::normalize($slug);

        return $this->pageRepository->slugExistsAmongSiblings($parentId, $normalizedSlug, $id);
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
