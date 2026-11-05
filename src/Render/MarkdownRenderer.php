<?php

declare(strict_types=1);

namespace Garner\Render;

use Closure;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Extension\Attributes\AttributesExtension;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\DisallowedRawHtml\DisallowedRawHtmlExtension;
use League\CommonMark\Extension\ExtensionInterface;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\TaskList\TaskListExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Util\HtmlFilter;
use League\Config\Exception\ConfigurationExceptionInterface;
use ReflectionClass;
use RuntimeException;
use Stringable;
use Throwable;

final class MarkdownRenderer
{
    /**
     * Registered by Garner itself; GithubFlavoredMarkdownExtension adds the
     * rest of the list. A site entry naming one of them is skipped: copying
     * League's usual setup into `extensions` would otherwise register a
     * delimiter twice and fail on every render. A test checks the list still
     * covers what GithubFlavoredMarkdownExtension registers.
     *
     * @var list<class-string<ExtensionInterface>>
     */
    private const array DEFAULTS = [
        CommonMarkCoreExtension::class,
        GithubFlavoredMarkdownExtension::class,
        AttributesExtension::class,
        AutolinkExtension::class,
        DisallowedRawHtmlExtension::class,
        StrikethroughExtension::class,
        TableExtension::class,
        TaskListExtension::class,
    ];

    /** @var array<string, mixed> */
    private readonly array $options;

    /**
     * Entries normalized: an instance or a class name as given, a callable
     * as a Closure.
     *
     * @var array<int|string, ExtensionInterface|class-string<ExtensionInterface>|Closure>
     */
    private readonly array $extensions;

    private ?MarkdownConverter $converter = null;

    private ?RuntimeException $failure = null;

    /**
     * Entries are checked here, so a malformed list fails at boot; the
     * environment itself is built on the first render, so requests that
     * render no Markdown never construct an extension or run a callable.
     *
     * @param array<string, mixed> $config The `app.markdown` config: League
     *        CommonMark options (including an added extension's own settings,
     *        such as `footnote`), plus `extensions`, the site's additions.
     */
    public function __construct(array $config = [])
    {
        $extensions = $config['extensions'] ?? [];
        unset($config['extensions']);

        if (!is_array($extensions)) {
            throw new RuntimeException(sprintf(
                'app.markdown.extensions must be a list, got %s',
                get_debug_type($extensions),
            ));
        }

        $normalized = [];

        foreach ($extensions as $key => $extension) {
            $normalized[$key] = self::normalizeEntry($key, $extension);
        }

        $this->extensions = $normalized;
        $this->options = $config;
    }

    public function render(mixed $value): string
    {
        $markdown = $this->normalize($value);

        if ($markdown === '') {
            return '';
        }

        return (string) $this->converter()->convert($markdown);
    }

    private function converter(): MarkdownConverter
    {
        if ($this->failure instanceof RuntimeException) {
            throw $this->failure;
        }

        try {
            return $this->converter ??= $this->build();
        } catch (RuntimeException $exception) {
            // Kept, so later renders in the request don't rerun callables
            // and constructors against a fresh environment.
            throw $this->failure = $exception;
        }
    }

    private function build(): MarkdownConverter
    {
        $environment = new Environment([
            'allow_unsafe_links' => false,
            'html_input' => HtmlFilter::STRIP,
            ...$this->options,
        ]);

        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());
        $environment->addExtension(new AttributesExtension());

        // Shared with the callables' registrations, which run later, inside
        // League's setup.
        $state = new class {
            /** @var array<class-string, int|string> class => entry key */
            public array $added = [];

            public ?RuntimeException $failure = null;

            public bool $running = false;
        };

        foreach ($this->extensions as $key => $entry) {
            if (!$entry instanceof Closure) {
                try {
                    self::add(
                        $environment,
                        is_string($entry) ? new $entry() : $entry,
                        $key,
                        $state->added,
                    );
                } catch (Throwable $exception) {
                    throw self::failed($key, $exception);
                }

                continue;
            }

            // A callable runs where League registers extensions, in its place
            // among them, so a listener or renderer it adds lines up with
            // ones added by the extensions before and after it. An extension
            // it returns is registered in League's next pass, as the parts of
            // GithubFlavoredMarkdownExtension are.
            $environment->addExtension(self::deferred(static function () use (
                $environment,
                $entry,
                $key,
                $state,
            ): void {
                // Reading from the Environment during setup starts setup
                // again, which would call this callable again.
                if ($state->running) {
                    throw $state->failure = new RuntimeException(sprintf(
                        '%s could not be registered: a callable may add to the Environment '
                        . 'but not read from it',
                        self::entry($key),
                    ));
                }

                $state->running = true;

                try {
                    $extension = self::call($environment, $entry);

                    if ($extension instanceof ExtensionInterface) {
                        self::add($environment, $extension, $key, $state->added);
                    }
                } catch (Throwable $exception) {
                    throw $exception === $state->failure
                        ? $exception
                        : ($state->failure = self::failed($key, $exception));
                } finally {
                    $state->running = false;
                }
            }));
        }

        // League registers extensions, and validates each setting, lazily on
        // first use. Do both here so an invalid or misspelled setting, or a
        // clash between extensions, is reported against the config rather
        // than surfacing mid-render. Settings are read after setup: some
        // extensions (GFM's parts) only declare theirs while registering.
        try {
            $environment->getBlockStartParsers();

            foreach (array_keys($this->options) as $option) {
                if ($option === '') {
                    continue;
                }

                $environment->getConfiguration()->get($option);
            }
        } catch (ConfigurationExceptionInterface $exception) {
            throw new RuntimeException(
                sprintf('app.markdown has an invalid setting: %s', $exception->getMessage()),
                previous: $exception,
            );
        } catch (Throwable $exception) {
            if ($exception === $state->failure) {
                throw $exception;
            }

            throw new RuntimeException(
                sprintf(
                    'app.markdown could not set up its extensions: %s',
                    $exception->getMessage(),
                ),
                previous: $exception,
            );
        }

        return new MarkdownConverter($environment);
    }

    /**
     * One `app.markdown.extensions` entry: an ExtensionInterface instance, its
     * class name (constructed without arguments), or a callable receiving the
     * Environment for anything an extension object doesn't cover (a custom
     * renderer, a parser, an event listener). A callable may also return an
     * extension, which is then added.
     */
    /**
     * @return ExtensionInterface|class-string<ExtensionInterface>|Closure
     */
    private static function normalizeEntry(
        int|string $key,
        mixed $extension,
    ): ExtensionInterface|string|Closure {
        if ($extension instanceof ExtensionInterface) {
            return $extension;
        }

        if (is_string($extension) && is_subclass_of($extension, ExtensionInterface::class)) {
            $class = new ReflectionClass($extension);

            if (!$class->isInstantiable()) {
                throw new RuntimeException(sprintf(
                    '%s: %s is not an instantiable class (an interface, abstract class, '
                    . 'or one with a non-public constructor)',
                    self::entry($key),
                    $extension,
                ));
            }

            if (($class->getConstructor()?->getNumberOfRequiredParameters() ?? 0) > 0) {
                throw new RuntimeException(sprintf(
                    '%s: %s cannot be constructed without arguments; pass an instance instead',
                    self::entry($key),
                    $extension,
                ));
            }

            return $extension;
        }

        if (is_callable($extension)) {
            return Closure::fromCallable($extension);
        }

        throw new RuntimeException(sprintf(
            '%s must be an extension instance or class name (implementing %s), '
            . 'or a callable receiving the %s; got %s',
            self::entry($key),
            ExtensionInterface::class,
            Environment::class,
            is_string($extension) ? '"' . $extension . '"' : get_debug_type($extension),
        ));
    }

    /**
     * Runs a callable entry: null when it only configured the Environment
     * itself, or the extension it returned.
     */
    private static function call(Environment $environment, Closure $callable): ?ExtensionInterface
    {
        $result = $callable($environment);

        if ($result === null || $result instanceof ExtensionInterface) {
            return $result;
        }

        throw new RuntimeException(sprintf(
            'the callable must return nothing or an %s; got %s',
            ExtensionInterface::class,
            get_debug_type($result),
        ));
    }

    /**
     * Adds a site extension, skipping one Garner already registers and
     * refusing a second of the same class.
     *
     * @param array<class-string, int|string> $added class => entry key
     */
    private static function add(
        Environment $environment,
        ExtensionInterface $extension,
        int|string $key,
        array &$added,
    ): void {
        if (in_array($extension::class, self::DEFAULTS, true)) {
            return;
        }

        $previous = $added[$extension::class] ?? null;

        if ($previous !== null) {
            throw new RuntimeException(sprintf(
                '%s is already added by %s',
                $extension::class,
                self::entry($previous),
            ));
        }

        $environment->addExtension($extension);
        $added[$extension::class] = $key;
    }

    /**
     * Wraps a callable entry as an extension, so League calls it during
     * setup in the order it was added.
     */
    private static function deferred(Closure $register): ExtensionInterface
    {
        return new class($register) implements ExtensionInterface {
            public function __construct(
                private readonly Closure $register,
            ) {}

            public function register(EnvironmentBuilderInterface $environment): void
            {
                ($this->register)();
            }
        };
    }

    private static function failed(int|string $key, Throwable $exception): RuntimeException
    {
        return new RuntimeException(
            sprintf('%s could not be registered: %s', self::entry($key), $exception->getMessage()),
            previous: $exception,
        );
    }

    private static function entry(int|string $key): string
    {
        return sprintf(
            'app.markdown.extensions[%s]',
            is_int($key) ? (string) $key : '"' . $key . '"',
        );
    }

    private function normalize(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        return '';
    }
}
