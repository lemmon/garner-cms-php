<?php

declare(strict_types=1);

namespace Garner\Site;

final class Page
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private readonly array $data,
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

    public function status(): string
    {
        return (string) ($this->data['status'] ?? 'draft');
    }

    public function template(): string
    {
        $template = $this->data['template'] ?? 'default';

        return is_string($template) && $template !== '' ? $template : 'default';
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

    public function value(string $key, mixed $default = null): mixed
    {
        $fields = $this->fields();

        return array_key_exists($key, $fields) ? $fields[$key] : $default;
    }
}
