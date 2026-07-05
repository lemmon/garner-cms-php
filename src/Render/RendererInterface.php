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
     * Render a single named fragment of the page's template — a Twig block —
     * with the same context shape as a full page render. The htmx "template
     * fragments" pattern: fragments live inside the page template they belong
     * to, no separate partial file.
     *
     * The block renders alone: {% set %} statements outside it do not run,
     * so a fragment block must be self-contained — derived values belong in
     * the controller (part of this context) or inside the block. Under
     * strict_variables an outside dependency throws; under lax config it
     * silently renders empty.
     *
     * @param array<string, mixed> $data
     */
    public function renderPageFragment(
        Page $page,
        Site $site,
        string $fragment,
        array $data = [],
    ): string;

    /**
     * @param array<string, mixed> $data
     */
    public function renderError(Site $site, int $status, string $kind, array $data = []): string;

    public function renderNotFound(Site $site, string $path): string;
}
