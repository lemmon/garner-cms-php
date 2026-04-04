<?php

declare(strict_types=1);

namespace Garner\Content;

use Illuminate\Support\Collection;
use RuntimeException;

final class PageRepository
{
    public function __construct(
        private readonly string $contentPath,
        private readonly string $defaultTemplate = 'default',
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function all(): Collection
    {
        $files = glob($this->pagesPath() . '/*/+page.json');

        return Collection::make($files === false ? [] : $files)
            ->map($this->decodeFile(...))
            ->sortBy([
                static fn(array $page): string => (string) ($page['parent_id'] ?? ''),
                static fn(array $page): int => is_int($page['sort'] ?? null)
                    ? $page['sort']
                    : PHP_INT_MAX,
                static fn(array $page): string => (string) ($page['slug'] ?? ''),
            ])
            ->values();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $file = $this->pageFilePath($id);

        if (!is_file($file)) {
            return null;
        }

        return $this->decodeFile($file);
    }

    /**
     * @param array<string, mixed> $page
     */
    public function save(array $page): void
    {
        $document = $this->normalizePage($page);
        $directory = dirname($this->pageFilePath((string) $document['id']));

        if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create page directory "%s"', $directory));
        }

        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (!is_string($json)) {
            throw new RuntimeException('Unable to encode page document');
        }

        file_put_contents($this->pageFilePath((string) $document['id']), $json . PHP_EOL);
    }

    public function pageFilePath(string $id): string
    {
        return $this->pagesPath() . '/' . $id . '/+page.json';
    }

    public function pagesPath(): string
    {
        return $this->contentPath . '/pages';
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeFile(string $file): array
    {
        $decoded = json_decode((string) file_get_contents($file), true);

        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('Invalid page JSON in "%s"', $file));
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $page
     * @return array<string, mixed>
     */
    private function normalizePage(array $page): array
    {
        $id = $page['id'] ?? null;
        $slug = $page['slug'] ?? null;

        if (!is_string($id) || $id === '') {
            throw new RuntimeException('Page "id" is required');
        }

        if ($slug !== null && !is_string($slug)) {
            throw new RuntimeException('Page "slug" must be a string or null');
        }

        $blueprint = $page['blueprint'] ?? 'default';
        $template = $page['template'] ?? $blueprint;
        $now = gmdate(DATE_ATOM);
        $normalizedSlug = is_string($slug) ? trim($slug, '/') : null;
        $sort = $page['sort'] ?? null;

        if ($normalizedSlug === '') {
            $normalizedSlug = null;
        }

        return [
            'id' => $id,
            'kind' => is_string($page['kind'] ?? null) ? $page['kind'] : 'page',
            'parent_id' => is_string($page['parent_id'] ?? null) ? $page['parent_id'] : null,
            'slug' => $normalizedSlug,
            'blueprint' => is_string($blueprint) ? $blueprint : 'default',
            'template' => is_string($template) ? $template : $this->defaultTemplate,
            'status' => is_string($page['status'] ?? null) ? $page['status'] : 'draft',
            'sort' => $sort !== null ? (int) $sort : null,
            'fields' => is_array($page['fields'] ?? null) ? $page['fields'] : [],
            'created_at' => is_string($page['created_at'] ?? null) ? $page['created_at'] : $now,
            'updated_at' => is_string($page['updated_at'] ?? null) ? $page['updated_at'] : $now,
        ];
    }
}
