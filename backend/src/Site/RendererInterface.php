<?php

declare(strict_types=1);

namespace Garner\Site;

interface RendererInterface
{
    public function renderNotFound(Site $site, Pages $pages, string $path): string;

    /**
     * @param array<string, mixed> $data
     */
    public function renderPage(Page $page, Site $site, Pages $pages, array $data = []): string;
}
