<?php

declare(strict_types=1);

namespace Garner\Site;

use Garner\Support\Identifier;
use Illuminate\Support\Collection;

final class Page
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private readonly array $data,
        private readonly ?Pages $pages = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->data;
    }

    /**
     * @return array<string, mixed>
     */
    public function fields(): array
    {
        return is_array($this->data['fields'] ?? null) ? $this->data['fields'] : [];
    }

    public function id(): string
    {
        return (string) ($this->data['id'] ?? '');
    }

    public function parentId(): ?string
    {
        return is_string($this->data['parent_id'] ?? null) ? $this->data['parent_id'] : null;
    }

    public function resolvedPath(): ?string
    {
        return is_string($this->data['resolved_path'] ?? null)
            ? $this->data['resolved_path']
            : null;
    }

    public function slug(): ?string
    {
        return is_string($this->data['slug'] ?? null) ? $this->data['slug'] : null;
    }

    public function status(): ?string
    {
        return is_string($this->data['status'] ?? null) ? $this->data['status'] : null;
    }

    public function template(): string
    {
        $template = $this->data['template'] ?? 'default';

        if (!is_string($template) || $template === '') {
            return 'default';
        }

        $normalized = Identifier::kebab($template);

        return $normalized !== '' ? $normalized : 'default';
    }

    public function blueprint(): string
    {
        $blueprint = $this->data['blueprint'] ?? 'default';

        if (!is_string($blueprint) || $blueprint === '') {
            return 'default';
        }

        $normalized = Identifier::kebab($blueprint);

        return $normalized !== '' ? $normalized : 'default';
    }

    public function title(): string
    {
        $title = $this->value('title');

        return is_string($title) && $title !== '' ? $title : $this->id();
    }

    public function url(): string
    {
        return $this->resolvedPath() ?? '#';
    }

    /**
     * @return Collection<int, Page>
     */
    public function children(bool $drafts = false): Collection
    {
        if ($this->pages === null) {
            return new Collection();
        }

        return $this->pages->childrenOf($this->id(), $drafts);
    }

    /**
     * @return Collection<int, Page>
     */
    public function index(bool $drafts = false): Collection
    {
        if ($this->pages === null) {
            return new Collection();
        }

        return $this->pages->indexOf($this->id(), $drafts);
    }

    public function value(string $key, mixed $default = null): mixed
    {
        $fields = $this->fields();

        return array_key_exists($key, $fields) ? $fields[$key] : $default;
    }
}
