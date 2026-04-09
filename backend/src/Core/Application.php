<?php

declare(strict_types=1);

namespace Garner\Core;

use Garner\Blueprint\BlueprintLoader;
use Garner\Content\PageRepository;
use Garner\Content\PathIndexer;
use Garner\Content\PathResolver;
use Garner\Content\SiteRepository;
use Garner\Site\CustomRoutes;
use Garner\Site\Favicon;
use Garner\Site\MarkdownRenderer;
use Garner\Site\PageControllers;
use Garner\Site\PublicSite;
use Garner\Site\RendererInterface;
use Garner\Site\TwigRenderer;
use Garner\Studio\StudioApp;

final class Application
{
    private ?BlueprintLoader $blueprintLoader = null;
    private ?CustomRoutes $customRoutes = null;
    private ErrorHandler $errorHandler;
    private ?Favicon $favicon = null;
    private ?MarkdownRenderer $markdownRenderer = null;
    private ?PageControllers $pageControllers = null;
    private ?PageRepository $pageRepository = null;
    private ?PathIndexer $pathIndexer = null;
    private ?PathResolver $pathResolver = null;
    private ?PublicSite $publicSite = null;
    private ?RendererInterface $siteRenderer = null;
    private ?SiteRepository $siteRepository = null;
    private ?StudioApp $studioApp = null;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly string $corePath,
        private readonly string $projectRootPath,
        private readonly array $config = [],
    ) {
        $this->errorHandler = new ErrorHandler($this);
    }

    public function run(): void
    {
        $this->errorHandler->register();
        (new Router($this, $this->backendPath()))->dispatch();
    }

    public function backendPath(): string
    {
        return $this->corePath . '/backend';
    }

    public function corePath(): string
    {
        return $this->corePath;
    }

    public function rootPath(): string
    {
        return $this->projectRootPath;
    }

    public function projectPath(string $key): string
    {
        $relativePath = $this->config('app.paths.' . $key, $key);

        if (!is_string($relativePath) || $relativePath === '') {
            throw new \RuntimeException(sprintf('Invalid project path config for "%s"', $key));
        }

        return $this->projectRootPath . '/' . ltrim($relativePath, '/');
    }

    public function config(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = $this->config;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public function pageRepository(): PageRepository
    {
        return $this->pageRepository ??= new PageRepository(
            contentPath: $this->projectPath('content'),
            defaultTemplate: (string) $this->config('app.rendering.default_template', 'default'),
        );
    }

    public function blueprintLoader(): BlueprintLoader
    {
        return $this->blueprintLoader ??= new BlueprintLoader(
            blueprintsPath: $this->projectPath('site') . '/blueprints',
        );
    }

    public function favicon(): Favicon
    {
        return $this->favicon ??= new Favicon(sitePath: $this->projectPath('site'));
    }

    public function customRoutes(): CustomRoutes
    {
        return $this->customRoutes ??= new CustomRoutes(
            routesFile: $this->projectPath('site') . '/routes.php',
        );
    }

    public function pageControllers(): PageControllers
    {
        return $this->pageControllers ??= new PageControllers(
            controllersPath: $this->projectPath('site') . '/controllers',
        );
    }

    public function markdownRenderer(): MarkdownRenderer
    {
        $config = $this->config('app.markdown', []);

        return $this->markdownRenderer ??= new MarkdownRenderer(is_array($config) ? $config : []);
    }

    public function pathIndexer(): PathIndexer
    {
        return $this->pathIndexer ??= new PathIndexer(
            siteRepository: $this->siteRepository(),
            pageRepository: $this->pageRepository(),
            sqlitePath: $this->projectPath('runtime') . '/index.sqlite',
        );
    }

    public function pathResolver(): PathResolver
    {
        return $this->pathResolver ??= new PathResolver(
            sqlitePath: $this->projectPath('runtime') . '/index.sqlite',
            pageRepository: $this->pageRepository(),
        );
    }

    public function publicSite(): PublicSite
    {
        return $this->publicSite ??= new PublicSite(
            app: $this,
            siteRepository: $this->siteRepository(),
            pageRepository: $this->pageRepository(),
            pathIndexer: $this->pathIndexer(),
            pathResolver: $this->pathResolver(),
            pageControllers: $this->pageControllers(),
            renderer: $this->siteRenderer(),
            indexPath: $this->projectPath('runtime') . '/index.sqlite',
        );
    }

    public function studioApp(): StudioApp
    {
        $buildPath = $this->config('app.studio.build_path', 'frontend/build');

        if (!is_string($buildPath) || $buildPath === '') {
            throw new \RuntimeException('Invalid Studio build path configuration');
        }

        $prefix = (string) $this->config('app.routes.studio_prefix', '/studio');

        $resolvedBuildPath = str_starts_with($buildPath, '/')
            ? $buildPath
            : $this->corePath . '/' . ltrim($buildPath, '/');

        return $this->studioApp ??= new StudioApp(buildPath: $resolvedBuildPath, prefix: $prefix);
    }

    public function siteRepository(): SiteRepository
    {
        return $this->siteRepository ??= new SiteRepository(contentPath: $this->projectPath(
            'content',
        ));
    }

    public function siteRenderer(): RendererInterface
    {
        $twigConfig = $this->config('app.twig', []);
        $twigOptions = $this->twigOptions(is_array($twigConfig) ? $twigConfig : []);

        return $this->siteRenderer ??= new TwigRenderer(
            templatesPath: $this->projectPath('site') . '/templates',
            defaultTemplate: (string) $this->config('app.rendering.default_template', 'default'),
            markdownRenderer: $this->markdownRenderer(),
            options: $twigOptions,
        );
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function twigOptions(array $config): array
    {
        $debug = is_bool($config['debug'] ?? null)
            ? $config['debug']
            : (bool) $this->config('app.debug', false);

        $autoReload = is_bool($config['auto_reload'] ?? null) ? $config['auto_reload'] : $debug;
        $options = [
            'auto_reload' => $autoReload,
            'cache' => $this->resolveTwigCache($config['cache'] ?? null, $debug),
            'debug' => $debug,
            'strict_variables' => (bool) ($config['strict_variables'] ?? false),
        ];

        if (is_string($config['charset'] ?? null) && $config['charset'] !== '') {
            $options['charset'] = $config['charset'];
        }

        return $options;
    }

    private function resolveTwigCache(mixed $cache, bool $debug): string|false
    {
        if ($cache === null) {
            return $debug ? false : $this->projectPath('runtime') . '/cache/twig';
        }

        if (!is_string($cache) || $cache === '') {
            return false;
        }

        return str_starts_with($cache, '/') ? $cache : $this->rootPath() . '/' . ltrim($cache, '/');
    }
}
