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
use Garner\Render\RenderedResponse;
use Garner\Render\RendererInterface;
use Garner\Render\TwigRenderer;
use Garner\Support\CallbackIdGenerator;
use Garner\Support\IdGenerator;
use Garner\Support\IdGeneratorType;
use Garner\Support\SecureRandomIdGenerator;
use RuntimeException;
use Twig\Environment;

final class Application
{
    private ?Cache $cache = null;
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
    private ?Session $session = null;
    private ?SessionStore $sessionStore = null;
    private ?SiteLoader $siteLoader = null;
    private ?string $siteUrl = null;
    private ?Store $store = null;
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
        return $this->scoped(
            $request,
            fn(): ?Request => $this->request,
            function (?Request $value): void {
                $this->request = $value;
            },
            $callback,
        );
    }

    /**
     * The current session, created on first access. Merely calling this
     * (e.g. to read a value that isn't there) activates nothing by itself —
     * no store write and no cookie happen until session code calls set(),
     * flash(), or destroy(). See attachSessionCookie(), which persists
     * changes and attaches the session cookie once per request.
     *
     * Session ids come from a dedicated CSPRNG generator, never from
     * idGenerator(): app.ids.generator exists for scaffolded content ids
     * and a project may legitimately make those predictable (sequential,
     * slug-derived), but a session id is a bearer token — a guessable one
     * would let a visitor load someone else's session.
     */
    public function session(): Session
    {
        return $this->session ??= Session::fromCookie(
            store: $this->sessionStore(),
            idGenerator: new SecureRandomIdGenerator(),
            lifetime: $this->sessionLifetime(),
            cookieId: $this->request()->cookie($this->sessionCookieName()),
        );
    }

    /**
     * The session already built by session(), or null when nothing in this
     * request has touched it yet. Router uses this at the end of dispatch so
     * a request that never calls session() skips persistence entirely
     * instead of creating one just to find it untouched.
     */
    public function sessionIfStarted(): ?Session
    {
        return $this->session;
    }

    /**
     * Run $callback with session() answering $session, restoring the
     * previous session afterwards. Lets tests inject a fake session (or a
     * store double) without touching the filesystem.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function withSession(Session $session, callable $callback): mixed
    {
        return $this->scoped(
            $session,
            fn(): ?Session => $this->session,
            function (?Session $value): void {
                $this->session = $value;
            },
            $callback,
        );
    }

    /**
     * Disposable application cache for computed values. The SQLite backing
     * file is created lazily on first set(); reads against an unused cache do
     * not create runtime state.
     */
    public function cache(): Cache
    {
        return $this->cache ??= new Cache($this->cachePath());
    }

    /**
     * Run $callback with cache() answering $cache, restoring the previous
     * cache afterwards. Mirrors withSession() and withStore() for tests and
     * scoped application work.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function withCache(Cache $cache, callable $callback): mixed
    {
        return $this->scoped(
            $cache,
            fn(): ?Cache => $this->cache,
            function (?Cache $value): void {
                $this->cache = $value;
            },
            $callback,
        );
    }

    public function sessionStore(): SessionStore
    {
        return $this->sessionStore ??= $this->makeSessionStore($this->config('app.session.store'));
    }

    /**
     * The site's durable key-value store (see Store). Obtaining it costs
     * nothing: the SQLite file behind it is only created on first write,
     * so a site that never stores anything never grows a
     * storage/store.sqlite.
     */
    public function store(): Store
    {
        return $this->store ??= new Store($this->storePath());
    }

    /**
     * Run $callback with store() answering $store, restoring the previous
     * store afterwards. Same callback-scoped shape as withSession(): a test
     * can point the store at a temp file (or a prepared fixture) without
     * leaking it into whatever runs next.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function withStore(Store $store, callable $callback): mixed
    {
        return $this->scoped(
            $store,
            fn(): ?Store => $this->store,
            function (?Store $value): void {
                $this->store = $value;
            },
            $callback,
        );
    }

    /**
     * Run $callback with a scoped property temporarily set to $value,
     * restoring its previous value afterward — the shared shape behind
     * withRequest(), withSession(), withStore(), and withCache().
     *
     * @template T
     * @param T $value
     * @param callable(): T $get
     * @param callable(T): void $set
     * @param callable(): mixed $callback
     */
    private function scoped(mixed $value, callable $get, callable $set, callable $callback): mixed
    {
        $previous = $get();
        $set($value);

        try {
            return $callback();
        } finally {
            $set($previous);
        }
    }

    /**
     * Where the store's SQLite file lives: `app.store.path` when set
     * (absolute, or relative to the project root), otherwise
     * `storage/store.sqlite`. Deliberately under storage/, not runtime/:
     * store data is canonical — there is nothing to rebuild it from — so
     * it follows the "back up storage/, ignore runtime/" contract.
     */
    private function storePath(): string
    {
        $configured = $this->config('app.store.path');

        if (is_string($configured) && $configured !== '') {
            return str_starts_with($configured, '/')
                ? $configured
                : $this->projectRootPath . '/' . ltrim($configured, '/');
        }

        return $this->projectPath('storage') . '/store.sqlite';
    }

    /**
     * Persist the session (if this request touched it) and attach its cookie
     * to $response. The single seam session state reaches the response
     * through — called by both response emitters, Router::emit() and
     * ErrorHandler::emit(), so session changes survive even when the request
     * ends in an error page. A request that never touched the session
     * (sessionIfStarted() is null) gets no cookie at all, keeping plain
     * content pages stateless and cache-friendly.
     */
    public function attachSessionCookie(RenderedResponse $response): RenderedResponse
    {
        $session = $this->sessionIfStarted();

        if ($session === null) {
            return $response;
        }

        $name = $this->sessionCookieName();

        if ($session->wasDestroyed()) {
            return $response->withCookie($name, '', expires: 1);
        }

        $id = $session->save();

        if ($id === null) {
            return $response;
        }

        return $response->withCookie(
            $name,
            $id,
            expires: time() + $this->sessionLifetime(),
            secure: $this->request()->isHttps(),
        );
    }

    /**
     * The session cookie's name (`app.session.cookie`, default
     * "garner_session").
     */
    public function sessionCookieName(): string
    {
        $configured = $this->config('app.session.cookie');

        return is_string($configured) && $configured !== '' ? $configured : 'garner_session';
    }

    /**
     * How long, in seconds, a session (and its cookie) stays alive after its
     * last write (`app.session.lifetime`, default 2 hours).
     */
    public function sessionLifetime(): int
    {
        $configured = $this->config('app.session.lifetime');

        return is_int($configured) && $configured > 0 ? $configured : 7200;
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

        return $this->request()->origin();
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

    private function makeSessionStore(mixed $configured): SessionStore
    {
        if ($configured === null) {
            return new FileSessionStore($this->sessionStorePath());
        }

        if ($configured instanceof SessionStore) {
            return $configured;
        }

        if (
            is_string($configured)
            && class_exists($configured)
            && is_subclass_of($configured, SessionStore::class)
        ) {
            /** @var class-string<SessionStore> $configured */
            return new $configured();
        }

        if (is_callable($configured)) {
            $store = $configured();

            if ($store instanceof SessionStore) {
                return $store;
            }
        }

        throw new RuntimeException('Invalid session store configuration');
    }

    /**
     * Where the default FileSessionStore keeps session files:
     * `app.session.path` when set (absolute, or relative to the project
     * root), otherwise `storage/sessions`.
     */
    private function sessionStorePath(): string
    {
        $configured = $this->config('app.session.path');

        if (is_string($configured) && $configured !== '') {
            return str_starts_with($configured, '/')
                ? $configured
                : $this->projectRootPath . '/' . ltrim($configured, '/');
        }

        return $this->projectPath('storage') . '/sessions';
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

    /**
     * Where the disposable application cache keeps its SQLite file:
     * `app.cache.path` when configured (absolute, or relative to the project
     * root), otherwise `runtime/cache/data.sqlite`.
     */
    public function cachePath(): string
    {
        $configured = $this->config('app.cache.path');

        if (!is_string($configured) || $configured === '') {
            return $this->projectPath('runtime') . '/cache/data.sqlite';
        }

        return str_starts_with($configured, '/')
            ? $configured
            : $this->rootPath() . '/' . ltrim($configured, '/');
    }
}
