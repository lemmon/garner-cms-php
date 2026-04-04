<?php

declare(strict_types=1);

namespace Garner\Content;

use RuntimeException;

final class SiteRepository
{
    public function __construct(
        private readonly string $contentPath,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function read(): array
    {
        $file = $this->siteFilePath();

        if (!is_file($file)) {
            return [
                'id' => 'site',
                'home_page_id' => null,
                'title' => 'Garner CMS',
            ];
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('Invalid site JSON in "%s"', $file));
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $site
     */
    public function save(array $site): void
    {
        if (
            !is_dir($this->contentPath)
            && !mkdir($this->contentPath, 0o777, true)
            && !is_dir($this->contentPath)
        ) {
            throw new RuntimeException(sprintf(
                'Unable to create content directory "%s"',
                $this->contentPath,
            ));
        }

        $document = [
            'id' => 'site',
            'title' => is_string($site['title'] ?? null) ? $site['title'] : 'Garner CMS',
            'home_page_id' => is_string($site['home_page_id'] ?? null)
                ? $site['home_page_id']
                : null,
            'updated_at' => is_string($site['updated_at'] ?? null)
                ? $site['updated_at']
                : gmdate(DATE_ATOM),
        ];

        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (!is_string($json)) {
            throw new RuntimeException('Unable to encode site document');
        }

        file_put_contents($this->siteFilePath(), $json . PHP_EOL);
    }

    public function siteFilePath(): string
    {
        return $this->contentPath . '/+site.json';
    }
}
