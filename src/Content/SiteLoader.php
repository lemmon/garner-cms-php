<?php

declare(strict_types=1);

namespace Garner\Content;

final class SiteLoader
{
    private const CANDIDATES = ['+site.json', '+site.yaml', '+site.yml'];

    public function __construct(
        private readonly string $contentPath,
        private readonly string $defaultTitle = 'Garner',
        private readonly string $baseUrl = '',
    ) {}

    public function load(?Pages $pages = null): Site
    {
        foreach (self::CANDIDATES as $candidate) {
            $path = $this->contentPath . '/' . $candidate;

            if (!is_file($path)) {
                continue;
            }

            $data = FormatParser::parse($path);

            if (!is_array($data)) {
                continue;
            }

            if (!is_string($data['title'] ?? null) || $data['title'] === '') {
                $data['title'] = $this->defaultTitle;
            }

            return new Site($data, $pages, $this->baseUrl);
        }

        return new Site(['title' => $this->defaultTitle], $pages, $this->baseUrl);
    }
}
