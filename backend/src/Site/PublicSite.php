<?php

declare(strict_types=1);

namespace Garner\Site;

use Garner\Content\PageRepository;
use Garner\Content\PathIndexer;
use Garner\Content\PathResolver;
use Garner\Content\SiteRepository;
use Garner\Core\Application;

final class PublicSite
{
    public function __construct(
        private readonly Application $app,
        private readonly SiteRepository $siteRepository,
        private readonly PageRepository $pageRepository,
        private readonly PathIndexer $pathIndexer,
        private readonly PathResolver $pathResolver,
        private readonly PageControllers $pageControllers,
        private readonly RendererInterface $renderer,
        private readonly string $indexPath,
    ) {}

    public function respond(string $path): RenderedResponse
    {
        $this->ensureIndex();

        $site = new Site($this->siteRepository->read());
        $pages = new Pages($this->pageRepository, $this->pathResolver);

        $resolvedPage = $this->pathResolver->resolve($path);

        if (!is_array($resolvedPage)) {
            return RenderedResponse::html(
                $this->renderer->renderNotFound($site, $pages, $path),
                404,
            );
        }

        $page = new Page($resolvedPage);
        $controllerResult = $this->pageControllers->dispatch($page, $site, $pages, $this->app);

        if ($controllerResult instanceof RenderedResponse) {
            return $controllerResult;
        }

        return RenderedResponse::html(
            $this->renderer->renderPage($page, $site, $pages, $controllerResult),
            200,
        );
    }

    private function ensureIndex(): void
    {
        if (!is_file($this->indexPath) || $this->indexIsStale()) {
            $this->pathIndexer->rebuild();
        }
    }

    private function indexIsStale(): bool
    {
        $indexMtime = filemtime($this->indexPath);

        if ($indexMtime === false) {
            return true;
        }

        $latestContentMtime = $this->latestContentMtime();

        return $latestContentMtime > $indexMtime;
    }

    private function latestContentMtime(): int
    {
        $latest = 0;
        $paths = $this->pageFiles();
        $paths[] = $this->siteRepository->siteFilePath();

        foreach ($paths as $path) {
            if (!is_file($path)) {
                continue;
            }

            $mtime = filemtime($path);

            if ($mtime !== false) {
                $latest = max($latest, $mtime);
            }
        }

        return $latest;
    }

    /**
     * @return list<string>
     */
    private function pageFiles(): array
    {
        $files = glob($this->pageRepository->pagesPath() . '/*/+page.json');

        return $files !== false ? $files : [];
    }
}
