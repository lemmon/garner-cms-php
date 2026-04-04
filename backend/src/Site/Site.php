<?php

declare(strict_types=1);

namespace Garner\Site;

final class Site
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

    public function homePageId(): ?string
    {
        return is_string($this->data['home_page_id'] ?? null) ? $this->data['home_page_id'] : null;
    }

    public function title(): string
    {
        $title = $this->value('title');

        return is_string($title) && $title !== '' ? $title : 'Garner CMS';
    }

    public function value(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }
}
