<?php

declare(strict_types=1);

namespace Garner\Content;

final class Page
{
    /**
     * @param array<string, mixed> $meta         Full decoded entry document (freeform metadata).
     * @param array<string, mixed> $content      Parsed sibling content files, keyed by basename.
     * @param string|null          $templateFile   Absolute path to a co-located +template.twig, if present.
     * @param string|null          $controllerFile Absolute path to a co-located +controller.php, if present.
     * @param Pages|null           $pages          Repository used to resolve children/descendants lazily.
     */
    public function __construct(
        private readonly string $id,
        private readonly ?string $template,
        private readonly string $url,
        private readonly array $meta,
        private readonly array $content,
        private readonly bool $draft = false,
        private readonly int $sort = 0,
        private readonly ?string $templateFile = null,
        private readonly ?string $controllerFile = null,
        private readonly ?Pages $pages = null,
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function template(): ?string
    {
        return $this->template;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function isDraft(): bool
    {
        return $this->draft;
    }

    public function sort(): int
    {
        return $this->sort;
    }

    public function templateFile(): ?string
    {
        return $this->templateFile;
    }

    public function controllerFile(): ?string
    {
        return $this->controllerFile;
    }

    /**
     * Direct child pages (published only; pass drafts: true to include drafts).
     */
    public function children(bool $drafts = false): PageCollection
    {
        return $this->pages?->children($this->url, $drafts) ?? new PageCollection();
    }

    /**
     * All descendant pages (excluding self; published only by default).
     */
    public function index(bool $drafts = false): PageCollection
    {
        return $this->pages?->index($this->url, $drafts) ?? new PageCollection();
    }

    public function title(): ?string
    {
        return is_string($this->meta['title'] ?? null) ? $this->meta['title'] : null;
    }

    public function created(): ?string
    {
        return is_string($this->meta['created'] ?? null) ? $this->meta['created'] : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return $this->meta;
    }

    /**
     * @return array<string, mixed>
     */
    public function content(): array
    {
        return $this->content;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->meta[$key] ?? $default;
    }
}
