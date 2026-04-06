<?php

declare(strict_types=1);

namespace Garner\Studio;

use Garner\Content\PageRepository;
use Garner\Content\PathResolver;
use Garner\Content\SiteRepository;
use Garner\Site\Page;
use Garner\Site\Pages;
use Garner\Site\Site;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class PageListQuery
{
    public function __construct(
        private readonly SiteRepository $siteRepository,
        private readonly PageRepository $pageRepository,
        private readonly PathResolver $pathResolver,
        private readonly PageListItemSerializer $serializer,
    ) {}

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function query(array $payload): array
    {
        $sourceName = is_string($payload['source'] ?? null) ? trim($payload['source']) : '';
        $queryName = is_string($payload['query'] ?? null) ? trim($payload['query']) : '';

        if ($sourceName === '') {
            throw new InvalidArgumentException('Node query source is required');
        }

        $pages = new Pages($this->pageRepository, $this->pathResolver);
        $site = new Site($this->siteRepository->read(), $pages);
        $source = $this->resolveSource($site, $sourceName);

        if ($source === null) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported or missing node query source "%s"',
                $sourceName,
            ));
        }

        $effectiveQuery = $queryName === '' ? 'source.children(drafts: true)' : $queryName;
        $result = $this->resolveQueryResult($site, $source, $effectiveQuery);

        if ($result === null) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported page list query "%s"',
                $effectiveQuery,
            ));
        }

        return [
            'ok' => true,
            'type' => 'page_list',
            'source' => $sourceName,
            'query' => $effectiveQuery,
            'items' => $result
                ->map(
                    fn(Page $page): array => $this->serializePage(
                        $page,
                        $site,
                        $source,
                        $effectiveQuery,
                    ),
                )
                ->values()
                ->all(),
        ];
    }

    private function resolveSource(Site $site, string $source): Site|Page|null
    {
        if ($source === 'site') {
            return $site;
        }

        if ($source === 'site.home') {
            return $site->home();
        }

        if ($source === 'site.error_page') {
            return $site->errorPage();
        }

        if (preg_match('/^site\.page\((["\'])([^"\']+)\1\)$/', $source, $matches) === 1) {
            return $site->page($matches[2]);
        }

        return null;
    }

    /**
     * @return Collection<int, Page>|null
     */
    private function resolveQueryResult(Site $site, Site|Page $source, string $query): ?Collection
    {
        $normalized = preg_replace('/\s+/', '', strtolower($query));

        if (!is_string($normalized) || $normalized === '') {
            return null;
        }

        return match ($normalized) {
            'source.children', 'source.children(drafts:true)' => $source->children(true),
            'source.index', 'source.index(drafts:true)' => $source->index(true),
            'source.system_pages', 'source.systempages', 'source.system_pages()' => $source
            instanceof Site
                ? $site->systemPages(true)
                : null,
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePage(Page $page, Site $site, Site|Page $source, string $query): array
    {
        return $this->serializer->serialize($page, $site, $source, $query);
    }
}
