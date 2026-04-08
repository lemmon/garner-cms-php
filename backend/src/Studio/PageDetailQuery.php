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
use InvalidArgumentException;

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
        $id = is_string($payload['id'] ?? null) ? trim($payload['id']) : '';

        if ($id === '') {
            throw new InvalidArgumentException('Page id is required');
        }

        $pages = new Pages($this->pageRepository, $this->pathResolver);
        $site = new Site($this->siteRepository->read(), $pages);
        $page = $pages->find($id);

        if ($page === null) {
            throw new InvalidArgumentException(sprintf('Page "%s" was not found', $id));
        }

        $data = $page->data();
        $blueprintName = is_string($data['blueprint'] ?? null) ? $data['blueprint'] : 'page';
        [$blueprint, $blueprintIssue] = $this->loadBlueprint($blueprintName);
        $isHome = $site->homePageId() === $page->id();
        $isError = $site->errorPageId() === $page->id();

        return [
            'ok' => true,
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
                'is_system' => $isHome || $isError,
                'children_count' => $page->children(true)->count(),
                'fields' => $page->fields(),
            ],
            'blueprint' => $blueprint,
            'blueprint_issue' => $blueprintIssue,
        ];
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
