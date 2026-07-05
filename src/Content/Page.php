<?php

declare(strict_types=1);

namespace Garner\Content;

final class Page
{
    /**
     * Extensions a web server may run as code. Refused as assets so publishing can
     * never drop an executable script into the public web root.
     *
     * @var list<string>
     */
    private const UNSAFE_EXTENSIONS = [
        'php',
        'php3',
        'php4',
        'php5',
        'php7',
        'php8',
        'phtml',
        'phps',
        'pht',
        'phar',
    ];

    /**
     * @param string               $path           Route path (e.g. "/about") — the page's routing identity.
     * @param array<string, mixed> $meta         Full decoded entry document (freeform metadata).
     * @param array<string, mixed> $content      Parsed sibling content files, keyed by basename.
     * @param string               $dir            Absolute path to the page directory (owns its files).
     * @param string|null          $templateFile   Absolute path to a co-located +template.twig, if present.
     * @param string|null          $controllerFile Absolute path to a co-located +controller.php, if present.
     * @param string|null          $actionFile     Absolute path to a co-located +action.php, if present.
     * @param Pages|null           $pages          Repository used to resolve children/descendants lazily.
     * @param MediaPublisher|null  $publisher      Publishes owned files and resolves their public URLs.
     * @param string               $baseUrl        Site base URL (no trailing slash) used to compose url().
     * @param bool                 $endpoint       Route endpoint (controller-only directory), not a tree page.
     */
    public function __construct(
        private readonly string $id,
        private readonly ?string $template,
        private readonly string $path,
        private readonly array $meta,
        private readonly array $content,
        private readonly string $dir,
        private readonly bool $draft = false,
        private readonly int $sort = 0,
        private readonly ?string $templateFile = null,
        private readonly ?string $controllerFile = null,
        private readonly ?string $actionFile = null,
        private readonly ?Pages $pages = null,
        private readonly ?MediaPublisher $publisher = null,
        private readonly string $baseUrl = '',
        private readonly bool $endpoint = false,
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function template(): ?string
    {
        return $this->template;
    }

    /**
     * The page's route path (e.g. "/about"): its routing identity, independent of
     * where the site is hosted.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * The page's absolute URL: the site base URL plus the route path (e.g.
     * "https://example.com/about"). Use path() for the bare route path.
     */
    public function url(): string
    {
        return $this->baseUrl . $this->path;
    }

    public function isDraft(): bool
    {
        return $this->draft;
    }

    /**
     * Whether this is a route endpoint (a controller-only directory): routable
     * and dispatchable, but not part of the page tree.
     */
    public function isEndpoint(): bool
    {
        return $this->endpoint;
    }

    public function sort(): int
    {
        return $this->sort;
    }

    public function templateFile(): ?string
    {
        return $this->templateFile;
    }

    public function controllerFile(): ?string
    {
        return $this->controllerFile;
    }

    public function actionFile(): ?string
    {
        return $this->actionFile;
    }

    /**
     * Direct child pages (published only; pass drafts: true to include drafts).
     */
    public function children(bool $drafts = false): PageCollection
    {
        return $this->pages?->children($this->path, $drafts) ?? new PageCollection();
    }

    /**
     * All descendant pages (excluding self; published only by default).
     */
    public function index(bool $drafts = false): PageCollection
    {
        return $this->pages?->index($this->path, $drafts) ?? new PageCollection();
    }

    public function title(): ?string
    {
        return is_string($this->meta['title'] ?? null) ? $this->meta['title'] : null;
    }

    public function created(): ?string
    {
        return is_string($this->meta['created'] ?? null) ? $this->meta['created'] : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return $this->meta;
    }

    /**
     * @return array<string, mixed>
     */
    public function content(): array
    {
        return $this->content;
    }

    /**
     * A single file asset owned by this page, by filename, or null. Returns only
     * publishable assets (see isAssetFile): reserved (`+`/`.`) names, paths outside
     * the page directory, and parsed content files and their sidecars are rejected.
     */
    public function file(string $name): ?File
    {
        if (!self::isSafeAssetName($name) || !$this->hasEntry($name)) {
            return null;
        }

        $path = $this->dir . '/' . $name;

        return self::isAssetFile($path) ? new File($path, $this->publisher) : null;
    }

    /**
     * Whether the page directory contains an entry with this exact name. is_file() is
     * case-insensitive on macOS/Windows, so this guards against page.file('Logo.svg')
     * resolving logo.svg locally and publishing a URL that 404s on a case-sensitive
     * (Linux) deployment — keeping file() in step with files() on every platform.
     */
    private function hasEntry(string $name): bool
    {
        $names = scandir($this->dir);

        return $names !== false && in_array($name, $names, true);
    }

    /**
     * Page-owned file assets (images, documents, …), keyed by filename. Excludes the
     * entry, reserved `+`/`.` files, and parsed content files.
     */
    public function files(): FileCollection
    {
        $names = scandir($this->dir);

        if ($names === false) {
            return new FileCollection();
        }

        $files = [];

        foreach ($names as $name) {
            if (!self::isSafeAssetName($name)) {
                continue;
            }

            $path = $this->dir . '/' . $name;

            if (!self::isAssetFile($path)) {
                continue;
            }

            $files[$name] = new File($path, $this->publisher);
        }

        ksort($files);

        return new FileCollection($files);
    }

    /**
     * Whether a filename is safe to treat as a page asset — shared by file() and
     * files() so both accept exactly the same names. Reserved (`+`/`.`) prefixes and
     * path separators (`/`, and `\` which is valid on Unix but reserved here) are out.
     */
    private static function isSafeAssetName(string $name): bool
    {
        return (
            $name !== ''
            && !str_starts_with($name, '+')
            && !str_starts_with($name, '.')
            && !str_contains($name, '/')
            && !str_contains($name, '\\')
        );
    }

    /**
     * Whether a path is a publishable page asset — the single definition shared by
     * file(), files(), and the sidecar loader. An asset is a regular file (symlinks
     * are rejected so it cannot point outside the page directory, e.g. at
     * /etc/passwd) whose extension is neither a parsed content format (those are
     * exposed through `content`, never as assets) nor a server-executable one (so
     * publishing cannot place runnable code in the public web root).
     */
    public static function isAssetFile(string $path): bool
    {
        $filename = strtolower(basename($path));

        return (
            is_file($path)
            && !is_link($path)
            && !FormatParser::supportsContent(pathinfo($filename, PATHINFO_EXTENSION))
            && !self::hasExecutableSuffix($filename)
        );
    }

    /**
     * Whether any dot-separated suffix of the filename is server-executable. Broadly
     * configured PHP handlers run e.g. `avatar.php.jpg` on the leading `.php`, not just
     * the final extension, so every suffix is checked — not only PATHINFO_EXTENSION.
     */
    private static function hasExecutableSuffix(string $filename): bool
    {
        $segments = explode('.', $filename);
        array_shift($segments); // the basename itself is not a suffix

        foreach ($segments as $segment) {
            if (in_array($segment, self::UNSAFE_EXTENSIONS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a directory entry is sidecar metadata for a sibling asset
     * (photo.jpg.json next to photo.jpg) rather than a content value of its own — a
     * structured file (json/yaml/yml) whose name minus that extension is an asset.
     * Shared by the content loader and the validator so they classify identically.
     */
    public static function isAssetSidecar(string $dir, string $name): bool
    {
        if (!FormatParser::isEntryFormat(strtolower(pathinfo($name, PATHINFO_EXTENSION)))) {
            return false;
        }

        return self::isAssetFile($dir . '/' . pathinfo($name, PATHINFO_FILENAME));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->meta[$key] ?? $default;
    }
}
