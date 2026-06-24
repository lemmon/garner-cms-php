<?php

declare(strict_types=1);

namespace Garner\Render;

use Garner\Content\Page;
use Garner\Content\Site;

interface RendererInterface
{
    /**
     * @param array<string, mixed> $data
     */
    public function renderPage(Page $page, Site $site, array $data = []): string;

    /**
     * @param array<string, mixed> $data
     */
    public function renderError(Site $site, int $status, string $kind, array $data = []): string;

    public function renderNotFound(Site $site, string $path): string;
}
