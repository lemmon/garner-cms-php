<?php

declare(strict_types=1);

namespace Garner\Studio;

use Garner\Content\PageRepository;
use Garner\Content\PathIndexer;
use Garner\Content\PathResolver;
use Garner\Content\SiteRepository;
use Garner\Support\IdGenerator;
use InvalidArgumentException;
use RuntimeException;

final class PageCreate
{
    public function __construct(
        private readonly SiteRepository $siteRepository,
        private readonly PageRepository $pageRepository,
        private readonly PathIndexer $pathIndexer,
        private readonly PathResolver $pathResolver,
        private readonly IdGenerator $idGenerator,
    ) {}

    /**
     * @param array{source: string, title: string, slug: string} $validated
     * @return array<string, mixed>
     */
    public function create(array $validated): array
    {
        $source = $validated['source'];
        $title = $validated['title'];
        $slug = $validated['slug'];

        if ($this->slugExistsAmongSiblingsForSource($source, $slug)) {
            throw new InvalidArgumentException('Slug must be unique among sibling pages');
        }

        $parentId = $this->parentIdForSource($source);
        $id = $this->nextPageId();

        $page = [
            'id' => $id,
            'kind' => 'page',
            'parent_id' => $parentId,
            'slug' => $slug,
            'status' => 'draft',
            'fields' => [
                'title' => $title,
            ],
        ];

        $this->pageRepository->save($page);
        $this->pathIndexer->rebuild();
        $stored = $this->pageRepository->findOrFail($id);

        return [
            'ok' => true,
            'page' => [
                'id' => $id,
                'parent_id' => $parentId,
                'slug' => $slug,
                'title' => $title,
                'blueprint' => is_string($stored['blueprint'] ?? null)
                    ? $stored['blueprint']
                    : 'default',
                'template' => is_string($stored['template'] ?? null)
                    ? $stored['template']
                    : 'default',
                'status' => is_string($stored['status'] ?? null) ? $stored['status'] : null,
                'path' => $this->pathResolver->pathForId($id),
            ],
        ];
    }

    public function slugExistsAmongSiblingsForSource(string $source, string $slug): bool
    {
        $parentId = $this->parentIdForSource($source);

        return $this->pageRepository->slugExistsAmongSiblings($parentId, $slug);
    }

    private function parentIdForSource(string $source): string
    {
        if ($source === 'site') {
            return $this->homePageId();
        }

        if ($source === 'site.home') {
            return $this->homePageId();
        }

        if (preg_match('/^site\.page\((["\'])([^"\']+)\1\)$/', $source, $matches) === 1) {
            $pageId = $matches[2];

            $this->pageRepository->findOrFail($pageId);

            return $pageId;
        }

        throw new InvalidArgumentException(sprintf('Unsupported page create source "%s"', $source));
    }

    private function homePageId(): string
    {
        $site = $this->siteRepository->read();
        $homePageId = is_string($site['home_page_id'] ?? null) ? $site['home_page_id'] : null;

        if ($homePageId === null) {
            throw new InvalidArgumentException('Site home page is required to create a page');
        }

        $this->pageRepository->findOrFail($homePageId);

        return $homePageId;
    }

    private function nextPageId(): string
    {
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $candidate = trim($this->idGenerator->generate());

            if ($candidate === '') {
                throw new RuntimeException('Generated page ID cannot be empty');
            }

            if ($this->pageRepository->find($candidate) === null) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to generate a unique page ID');
    }
}
