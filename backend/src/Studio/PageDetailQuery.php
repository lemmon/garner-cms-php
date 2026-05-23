<?php

declare(strict_types=1);

namespace Garner\Studio;

use Garner\Blueprint\BlueprintException;
use Garner\Blueprint\BlueprintLoader;
use Garner\Content\PageRepository;
use Garner\Content\PathResolver;
use Garner\Content\SiteRepository;
use Garner\Site\Pages;
use Garner\Site\Site;

final class PageDetailQuery
{
    public function __construct(
        private readonly SiteRepository $siteRepository,
        private readonly PageRepository $pageRepository,
        private readonly PathResolver $pathResolver,
        private readonly BlueprintLoader $blueprintLoader,
    ) {}

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function query(array $payload): array
    {
        $pages = new Pages($this->pageRepository, $this->pathResolver);
        $site = new Site($this->siteRepository->read(), $pages);
        $page = $pages->findOrFail($payload['id'] ?? null);

        $data = $page->data();
        $blueprintName = $page->blueprint();
        [$blueprint, $blueprintIssue] = $this->loadBlueprint($blueprintName);
        $isHome = $site->homePageId() === $page->id();
        $isError = $site->errorPageId() === $page->id();
        $isSystem = $site->isSystemPage($page->id());

        return [
            'ok' => true,
            'breadcrumbs' => $this->buildBreadcrumbs($page, $pages),
            'page' => [
                'id' => $page->id(),
                'kind' => is_string($data['kind'] ?? null) ? $data['kind'] : 'page',
                'parent_id' => $page->parentId(),
                'slug' => $page->slug(),
                'status' => $page->status(),
                'sort' => is_int($data['sort'] ?? null) ? $data['sort'] : null,
                'blueprint' => $blueprintName,
                'template' => $page->template(),
                'title' => $page->title(),
                'path' => $page->resolvedPath(),
                'created_at' => is_string($data['created_at'] ?? null) ? $data['created_at'] : null,
                'updated_at' => is_string($data['updated_at'] ?? null) ? $data['updated_at'] : null,
                'is_home' => $isHome,
                'is_error' => $isError,
                'is_system' => $isSystem,
                'slug_editable' => !$isSystem,
                'status_editable' => !$isSystem,
                'children_count' => $page->children(true)->count(),
                'fields' => $page->fields(),
            ],
            'status_siblings' => $this->buildStatusSiblings($data),
            'blueprint' => $blueprint,
            'blueprint_issue' => $blueprintIssue,
        ];
    }

    /**
     * @param array<string, mixed> $page
     * @return list<array<string, mixed>>
     */
    private function buildStatusSiblings(array $page): array
    {
        $parentId = is_string($page['parent_id'] ?? null) ? $page['parent_id'] : null;

        return array_values(
            $this->pageRepository
                ->all()
                ->filter(static function (array $candidate) use ($parentId): bool {
                    $candidateParentId = is_string($candidate['parent_id'] ?? null)
                        ? $candidate['parent_id']
                        : null;

                    return $candidateParentId === $parentId
                    && is_string($candidate['status'] ?? null);
                })
                ->map(static function (array $candidate): array {
                    $fields = is_array($candidate['fields'] ?? null) ? $candidate['fields'] : [];
                    $title = is_string($fields['title'] ?? null) && $fields['title'] !== ''
                        ? $fields['title']
                        : (string) ($candidate['id'] ?? '');

                    return [
                        'id' => (string) ($candidate['id'] ?? ''),
                        'parent_id' => is_string($candidate['parent_id'] ?? null)
                            ? $candidate['parent_id']
                            : null,
                        'slug' => is_string($candidate['slug'] ?? null) ? $candidate['slug'] : null,
                        'status' => is_string($candidate['status'] ?? null)
                            ? $candidate['status']
                            : null,
                        'sort' => is_int($candidate['sort'] ?? null) ? $candidate['sort'] : null,
                        'title' => $title,
                    ];
                })
                ->values()
                ->all(),
        );
    }

    /**
     * @return list<array{label: string, href: string|null}>
     */
    private function buildBreadcrumbs(\Garner\Site\Page $page, Pages $pages): array
    {
        $ancestors = [];
        $parentId = $page->parentId();

        while ($parentId !== null) {
            $parent = $pages->find($parentId);

            if ($parent === null) {
                break;
            }

            $ancestors[] = $parent;
            $parentId = $parent->parentId();
        }

        $ancestors = array_reverse($ancestors);
        $breadcrumbs = [
            [
                'label' => 'Site',
                'href' => '/site',
            ],
        ];

        foreach ($ancestors as $ancestor) {
            $breadcrumbs[] = [
                'label' => $ancestor->title(),
                'href' => '/site/pages/' . $ancestor->id(),
            ];
        }

        $breadcrumbs[] = [
            'label' => $page->title(),
            'href' => null,
        ];

        return $breadcrumbs;
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: string|null}
     */
    private function loadBlueprint(string $name): array
    {
        try {
            return [$this->blueprintLoader->loadPage($name), null];
        } catch (BlueprintException $exception) {
            return [null, $exception->getMessage()];
        }
    }
}
