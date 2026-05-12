<?php

declare(strict_types=1);

namespace Garner\Studio;

use Garner\Content\PageRepository;
use Garner\Content\PathIndexer;
use Garner\Content\PathResolver;
use Garner\Content\SiteRepository;
use Garner\Site\Site;
use Garner\Support\Slug;
use InvalidArgumentException;

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
     * @param array<string, mixed> $validated Already-validated payload (title, slug, status, sort, position, and/or blueprint fields).
     * @return array<string, mixed>
     */
    public function update(array $page, array $validated): array
    {
        if ($validated === []) {
            throw new InvalidArgumentException('Page update requires at least one validated field');
        }

        $id = (string) $page['id'];
        $fields = is_array($page['fields'] ?? null) ? $page['fields'] : [];
        $indexChanged = false;

        if (array_key_exists('slug', $validated)) {
            $page['slug'] = $validated['slug'];
            $indexChanged = true;
        }

        if (array_key_exists('status', $validated)) {
            $status = $validated['status'];

            if (is_string($status) && $status !== ($page['status'] ?? null)) {
                $indexChanged = true;
            }

            $page['status'] = $status;
        }

        if (array_key_exists('sort', $validated) && !array_key_exists('position', $validated)) {
            $sort = $validated['sort'];

            if (is_int($sort) && $sort !== ($page['sort'] ?? null)) {
                $indexChanged = true;
            }

            $page['sort'] = $sort;
        }

        foreach ($validated as $name => $value) {
            if (in_array($name, ['slug', 'status', 'sort', 'position'], true)) {
                continue;
            }
            $fields[$name] = $value;
        }

        $page['fields'] = $fields;
        $page['updated_at'] = gmdate(DATE_ATOM);

        $position = $validated['position'] ?? null;
        $positionApplied = false;

        if (is_int($position) && ($page['status'] ?? null) === 'listed') {
            $this->saveListedPageAtPosition($page, $position);
            $indexChanged = true;
            $positionApplied = true;
        }

        if (!$positionApplied) {
            $this->pageRepository->save($page);
        }

        $stored = $this->pageRepository->findOrFail($id);

        if ($indexChanged) {
            $this->pathIndexer->rebuild();
        }

        return [
            'ok' => true,
            'page' => [
                'id' => $id,
                'slug' => is_string($stored['slug'] ?? null) ? $stored['slug'] : null,
                'status' => is_string($stored['status'] ?? null) ? $stored['status'] : null,
                'sort' => is_int($stored['sort'] ?? null) ? $stored['sort'] : null,
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
    public function statusEditableForPage(array $page): bool
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

    /**
     * @param array<string, mixed> $page
     */
    private function saveListedPageAtPosition(array $page, int $position): void
    {
        $id = (string) $page['id'];
        $parentId = is_string($page['parent_id'] ?? null) ? $page['parent_id'] : null;
        $siblings = $this->pageRepository
            ->all()
            ->filter(static function (array $candidate) use ($id, $parentId): bool {
                $candidateId = is_string($candidate['id'] ?? null) ? $candidate['id'] : '';
                $candidateParentId = is_string($candidate['parent_id'] ?? null)
                    ? $candidate['parent_id']
                    : null;

                return (
                    $candidateId !== ''
                    && $candidateId !== $id
                    && $candidateParentId === $parentId
                    && ($candidate['status'] ?? null) === 'listed'
                );
            })
            ->values()
            ->all();

        $boundedPosition = max(1, min($position, count($siblings) + 1));

        array_splice($siblings, $boundedPosition - 1, 0, [$page]);
        $updatedAt = is_string($page['updated_at'] ?? null)
            ? $page['updated_at']
            : gmdate(DATE_ATOM);

        foreach ($siblings as $index => $sibling) {
            $sort = ($index + 1) * 10;

            if (($sibling['sort'] ?? null) !== $sort) {
                $sibling['updated_at'] = $updatedAt;
            }

            $sibling['sort'] = $sort;
            $this->pageRepository->save($sibling);
        }
    }
}
