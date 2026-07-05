<?php

declare(strict_types=1);

namespace Garner\Core;

use Garner\Content\ContentIndex;
use Garner\Content\MediaPublisher;
use Garner\Content\PageLoader;
use Garner\Content\Pages;
use Garner\Content\PublicSite;
use Garner\Content\SiteLoader;
use Garner\Content\TreeValidator;
use Garner\Render\CustomRoutes;
use Garner\Render\Favicon;
use Garner\Render\MarkdownRenderer;
use Garner\Render\PageActions;
use Garner\Render\PageControllers;
use Garner\Render\RendererInterface;
use Garner\Render\TwigRenderer;
use Garner\Support\CallbackIdGenerator;
use Garner\Support\IdGenerator;
use Garner\Support\IdGeneratorType;
use RuntimeException;
use Twig\Environment;

final class Application
{
    private ?ContentIndex $contentIndex = null;
    private ?CustomRoutes $customRoutes = null;
    private ErrorHandler $errorHandler;
    private ?Favicon $favicon = null;
    private ?IdGenerator $idGenerator = null;
    private ?MarkdownRenderer $markdownRenderer = null;
    private ?MediaPublisher $mediaPublisher = null;
    private ?PageActions $pageActions = null;
    private ?PageControllers $pageControllers = null;
    private ?PageLoader $pageLoader = null;
    private ?Pages $pages = null;
    private ?PublicSite $publicSite = null;
    private ?Request $request;
    private ?RendererInterface $siteRenderer = null;
    private ?SiteLoader $siteLoader = null;
    private ?string $siteUrl = null;
    private ?TreeValidator $treeValidator = null;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly string $corePath,
        private readonly string $projectRootPath,
        private readonly array $config = [],
        ?Request $request = null,
    ) {
        $this->request = $request;
        $this->errorHandler = new ErrorHandler($this);
    }

    /**
     * The current HTTP request: the instance injected at construction (tests,
     * custom boot), otherwise built from PHP's globals on first use.
     */
    public function request(): Request
    {
        return $this->request ??= Request::fromGlobals();
    }

    /**
     * Run $callback with request() answering $request, restoring the previous
     * request afterwards. The action-failure re-render uses it to present the
     * read-side controllers with the request as a GET (Request::asGet()).
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function withRequest(Request $request, callable $callback): mixed
    {
        $previous = $this->request;
        $this->request = $request;

        try {
            return $callback();
        } finally {
            $this->request = $previous;
        }
    }

    public function run(): void
    {
        $this->errorHandler->register();
        new Router($this)->dispatch();
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
            throw new RuntimeException(sprintf('Invalid project path config for "%s"', $key));
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

    public function contentIndex(): ContentIndex
    {
        return $this->contentIndex ??= new ContentIndex(
            contentPath: $this->projectPath('routes'),
            sqlitePath: $this->projectPath('runtime') . '/index.sqlite',
            mode: $this->resolveIndexMode(),
        );
    }

    public function pageLoader(): PageLoader
    {
        return $this->pageLoader ??= new PageLoader(
            publisher: $this->mediaPublisher(),
            baseUrl: $this->siteUrl(),
        );
    }

    public function mediaPublisher(): MediaPublisher
    {
        return $this->mediaPublisher ??= new MediaPublisher(publicPath: $this->projectPath(
            'public',
        ));
    }

    public function treeValidator(): TreeValidator
    {
        return $this->treeValidator ??= new TreeValidator(contentPath: $this->projectPath(
            'routes',
        ));
    }

    public function pages(): Pages
    {
        return $this->pages ??= new Pages($this->contentIndex(), $this->pageLoader());
    }

    public function siteLoader(): SiteLoader
    {
        return $this->siteLoader ??= new SiteLoader(
            contentPath: $this->projectPath('routes'),
            defaultTitle: (string) $this->config('app.name', 'Garner'),
            baseUrl: $this->siteUrl(),
        );
    }

    /**
     * The site's base URL (scheme://host, no trailing slash): the `app.url` config
     * when set, otherwise inferred from the current request. Resolved once.
     */
    public function siteUrl(): string
    {
        return $this->siteUrl ??= $this->resolveSiteUrl();
    }

    private function resolveSiteUrl(): string
    {
        $configured = $this->config('app.url');

        if (is_string($configured) && trim($configured) !== '') {
            return rtrim(trim($configured), '/');
        }

        return $this->request()->baseUrl();
    }

    public function favicon(): Favicon
    {
        return $this->favicon ??= new Favicon(sitePath: $this->projectPath('app'));
    }

    public function customRoutes(): CustomRoutes
    {
        return $this->customRoutes ??= new CustomRoutes(
            routesFile: $this->projectPath('app') . '/routes.php',
        );
    }

    public function pageControllers(): PageControllers
    {
        return $this->pageControllers ??= new PageControllers(
            controllersPath: $this->projectPath('app') . '/controllers',
        );
    }

    public function pageActions(): PageActions
    {
        return $this->pageActions ??= new PageActions();
    }

    public function markdownRenderer(): MarkdownRenderer
    {
        $config = $this->config('app.markdown', []);

        return $this->markdownRenderer ??= new MarkdownRenderer(is_array($config) ? $config : []);
    }

    public function idGenerator(): IdGenerator
    {
        return $this->idGenerator ??= $this->makeIdGenerator($this->config(
            'app.ids.generator',
            IdGeneratorType::Cuid2,
        ));
    }

    public function siteRenderer(): RendererInterface
    {
        $twigConfig = $this->config('app.twig', []);
        $twigOptions = $this->twigOptions(is_array($twigConfig) ? $twigConfig : []);

        return $this->siteRenderer ??= new TwigRenderer(
            templatesPath: $this->projectPath('app') . '/templates',
            defaultTemplate: (string) $this->config('app.rendering.default_template', 'default'),
            markdownRenderer: $this->markdownRenderer(),
            options: $twigOptions,
            extensions: $this->twigExtensions(),
        );
    }

    /**
     * Site Twig extensions from app/twig.php — a file returning a callable
     * `(Environment $twig, Application $app): void` that registers functions,
     * filters, or globals. Returns the hook bound to this Application, or null
     * when the site defines none.
     *
     * @return (callable(Environment): void)|null
     */
    private function twigExtensions(): ?callable
    {
        $file = $this->projectPath('app') . '/twig.php';

        if (!is_file($file)) {
            return null;
        }

        $register = require $file;

        if (!is_callable($register)) {
            throw new RuntimeException(sprintf(
                'Twig extensions "%s" must return a callable',
                $file,
            ));
        }

        return function (Environment $twig) use ($register): void {
            $register($twig, $this);
        };
    }

    public function publicSite(): PublicSite
    {
        return $this->publicSite ??= new PublicSite(
            app: $this,
            pages: $this->pages(),
            siteLoader: $this->siteLoader(),
            controllers: $this->pageControllers(),
            actions: $this->pageActions(),
            renderer: $this->siteRenderer(),
        );
    }

    private function resolveIndexMode(): string
    {
        $configured = $this->config('app.index.mode');

        if ($configured === 'scan' || $configured === 'locked') {
            return $configured;
        }

        return (bool) $this->config('app.debug', false) ? 'scan' : 'locked';
    }

    private function makeIdGenerator(mixed $configured): IdGenerator
    {
        if ($configured === null) {
            return IdGeneratorType::Cuid2->create();
        }

        if ($configured instanceof IdGenerator) {
            return $configured;
        }

        if ($configured instanceof IdGeneratorType) {
            return $configured->create();
        }

        if (is_string($configured)) {
            $type = IdGeneratorType::tryFrom($configured);

            if ($type !== null) {
                return $type->create();
            }

            if (class_exists($configured) && is_subclass_of($configured, IdGenerator::class)) {
                /** @var class-string<IdGenerator> $configured */
                return new $configured();
            }
        }

        if (is_callable($configured)) {
            return new CallbackIdGenerator($configured);
        }

        throw new RuntimeException('Invalid ID generator configuration');
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
            return $debug ? false : $this->twigCachePath();
        }

        if (!is_string($cache) || $cache === '') {
            return false;
        }

        return $this->twigCachePath();
    }

    /**
     * Where compiled Twig templates accumulate: the `app.twig.cache` path when
     * configured, otherwise the default runtime location. Debug mode only decides
     * whether rendering uses the cache, not where it lives — so clearing (e.g.
     * `garner cache:clear` on deploy) targets the same path in every mode.
     */
    public function twigCachePath(): string
    {
        $cache = $this->config('app.twig.cache');

        if (!is_string($cache) || $cache === '') {
            return $this->projectPath('runtime') . '/cache/twig';
        }

        return str_starts_with($cache, '/') ? $cache : $this->rootPath() . '/' . ltrim($cache, '/');
    }
}
