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
            ->sort(self::comparePages(...))
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
        $status = $this->normalizeStatus($page, $blueprint, $template);

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
            'status' => $status,
            'sort' => $this->normalizeSort($page['sort'] ?? null, $status),
            'fields' => is_array($page['fields'] ?? null) ? $page['fields'] : [],
            'created_at' => is_string($page['created_at'] ?? null) ? $page['created_at'] : $now,
            'updated_at' => is_string($page['updated_at'] ?? null) ? $page['updated_at'] : $now,
        ];
    }

    /**
     * @param array<string, mixed> $page
     */
    private function normalizeStatus(array $page, mixed $blueprint, mixed $template): ?string
    {
        if (is_string($page['status'] ?? null)) {
            return $page['status'];
        }

        $blueprintName = is_string($blueprint) ? $blueprint : '';
        $templateName = is_string($template) ? $template : '';

        if (in_array($blueprintName, ['home', 'error'], true)) {
            return null;
        }

        if (in_array($templateName, ['home', 'error'], true)) {
            return null;
        }

        return 'draft';
    }

    private function normalizeSort(mixed $sort, ?string $status): ?int
    {
        if ($status === null || $status === 'unlisted') {
            return null;
        }

        return $sort !== null ? (int) $sort : null;
    }

    /**
     * @param array<string, mixed> $page
     */
    private static function systemRank(array $page): int
    {
        $blueprint = is_string($page['blueprint'] ?? null) ? $page['blueprint'] : '';
        $template = is_string($page['template'] ?? null) ? $page['template'] : '';

        if ($blueprint === 'home' || $template === 'home') {
            return 0;
        }

        if ($blueprint === 'error' || $template === 'error') {
            return 2;
        }

        return 1;
    }

    /**
     * @param array<string, mixed> $page
     */
    private static function statusRank(array $page): int
    {
        return match ($page['status'] ?? null) {
            'listed' => 0,
            'unlisted' => 1,
            'draft' => 2,
            default => 3,
        };
    }

    /**
     * @param array<string, mixed> $page
     */
    private static function listedSortKey(array $page): int
    {
        if (($page['status'] ?? null) !== 'listed') {
            return PHP_INT_MAX;
        }

        return is_int($page['sort'] ?? null) ? $page['sort'] : PHP_INT_MAX;
    }

    /**
     * @param array<string, mixed> $page
     */
    private static function slugOrId(array $page): string
    {
        $slug = is_string($page['slug'] ?? null) ? $page['slug'] : '';

        if ($slug !== '') {
            return $slug;
        }

        return is_string($page['id'] ?? null) ? $page['id'] : '';
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    private static function comparePages(array $left, array $right): int
    {
        return (
            (string) ($left['parent_id'] ?? '') <=> (string) ($right['parent_id'] ?? '')
            // @mago-expect lint:no-shorthand-ternary
            ?: self::systemRank($left) <=> self::systemRank($right)
            // @mago-expect lint:no-shorthand-ternary
            ?: self::statusRank($left) <=> self::statusRank($right)
            // @mago-expect lint:no-shorthand-ternary
            ?: self::listedSortKey($left) <=> self::listedSortKey($right)
            // @mago-expect lint:no-shorthand-ternary
            ?: self::slugOrId($left) <=> self::slugOrId($right)
        );
    }
}
