<?php

declare(strict_types=1);

namespace Garner\Content;

use Garner\Core\NotFoundException;
use Garner\Support\Identifier;
use Illuminate\Support\Collection;
use RuntimeException;

final class PageRepository
{
    use SortsPages;

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
    public function find(mixed $id): ?array
    {
        $normalizedId = $this->normalizeLookupId($id);

        if ($normalizedId === null) {
            return null;
        }

        $file = $this->pageFilePath($normalizedId);

        if (!is_file($file)) {
            return null;
        }

        return $this->decodeFile($file);
    }

    /**
     * @return array<string, mixed>
     */
    public function findOrFail(mixed $id): array
    {
        $page = $this->find($id);

        if (!is_array($page)) {
            throw new NotFoundException('Page not found');
        }

        return $page;
    }

    public function slugExistsAmongSiblings(
        ?string $parentId,
        string $slug,
        ?string $exceptId = null,
    ): bool {
        $normalizedSlug = $this->normalizeSlugForLookup($slug);

        if ($normalizedSlug === null) {
            return false;
        }

        foreach ($this->all() as $candidate) {
            $candidateId = is_string($candidate['id'] ?? null) ? $candidate['id'] : '';

            if ($candidateId === '' || $exceptId !== null && $candidateId === $exceptId) {
                continue;
            }

            $candidateParentId = is_string($candidate['parent_id'] ?? null)
                ? $candidate['parent_id']
                : null;

            if ($candidateParentId !== $parentId) {
                continue;
            }

            if ($this->normalizeSlugForLookup($candidate['slug'] ?? null) === $normalizedSlug) {
                return true;
            }
        }

        return false;
    }

    private function normalizeLookupId(mixed $id): ?string
    {
        if (!is_string($id)) {
            return null;
        }

        $normalizedId = trim($id);

        return $normalizedId !== '' ? $normalizedId : null;
    }

    private function normalizeSlugForLookup(mixed $slug): ?string
    {
        if (!is_string($slug)) {
            return null;
        }

        $normalizedSlug = trim($slug, '/');

        return $normalizedSlug !== '' ? $normalizedSlug : null;
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

        if (is_string($decoded['blueprint'] ?? null)) {
            $decoded['blueprint'] = Identifier::kebab($decoded['blueprint']);
        }

        if (is_string($decoded['template'] ?? null)) {
            $decoded['template'] = Identifier::kebab($decoded['template']);
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

        $template = $page['template'] ?? $this->defaultTemplate;
        $defaultBlueprint = Identifier::kebab($this->defaultTemplate);
        $defaultBlueprint = $defaultBlueprint !== '' ? $defaultBlueprint : 'default';
        $blueprint = $page['blueprint'] ?? $defaultBlueprint;
        $now = gmdate(DATE_ATOM);
        $normalizedSlug = is_string($slug) ? trim($slug, '/') : null;
        $normalizedBlueprint = is_string($blueprint)
            ? Identifier::kebab($blueprint)
            : $defaultBlueprint;
        $normalizedTemplate = is_string($template)
            ? Identifier::kebab($template)
            : $this->defaultTemplate;
        $status = $this->normalizeStatus($page);

        if ($normalizedSlug === '') {
            $normalizedSlug = null;
        }

        return [
            'id' => $id,
            'kind' => is_string($page['kind'] ?? null) ? $page['kind'] : 'page',
            'parent_id' => is_string($page['parent_id'] ?? null) ? $page['parent_id'] : null,
            'slug' => $normalizedSlug,
            'blueprint' => $normalizedBlueprint !== '' ? $normalizedBlueprint : $defaultBlueprint,
            'template' => $normalizedTemplate !== '' ? $normalizedTemplate : $this->defaultTemplate,
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
    private function normalizeStatus(array $page): ?string
    {
        if (array_key_exists('status', $page) && $page['status'] === null) {
            return null;
        }

        if (is_string($page['status'] ?? null)) {
            return $page['status'];
        }

        return 'draft';
    }

    private function normalizeSort(mixed $sort, ?string $status): ?int
    {
        if ($status !== 'listed') {
            return null;
        }

        return $sort !== null ? (int) $sort : null;
    }
}
