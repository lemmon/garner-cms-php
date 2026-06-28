<?php

declare(strict_types=1);

namespace Garner\Content;

use RuntimeException;

/**
 * A page-owned file asset on disk — an image, document, video, or any other
 * non-content file beside a page entry. A file's identity is its location;
 * metadata comes from an optional sibling sidecar (`photo.jpg` -> `photo.jpg.json`),
 * which is never created automatically.
 */
final class File
{
    /**
     * Extension -> MIME type for the common asset formats. Anything unlisted is
     * served as a generic binary stream.
     *
     * @var array<string, string>
     */
    private const MIME_TYPES = [
        'avif' => 'image/avif',
        'gif' => 'image/gif',
        'ico' => 'image/x-icon',
        'jpeg' => 'image/jpeg',
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
        'mp3' => 'audio/mpeg',
        'ogg' => 'audio/ogg',
        'wav' => 'audio/wav',
        'mp4' => 'video/mp4',
        'mov' => 'video/quicktime',
        'webm' => 'video/webm',
        'csv' => 'text/csv',
        'pdf' => 'application/pdf',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'zip' => 'application/zip',
    ];

    /**
     * @var array<string, mixed>|null
     */
    private ?array $meta = null;

    public function __construct(
        private readonly string $path,
        private readonly ?MediaPublisher $publisher = null,
    ) {}

    public function filename(): string
    {
        return basename($this->path);
    }

    /**
     * The filename without its extension ("photo" for "photo.jpg").
     */
    public function name(): string
    {
        return pathinfo($this->path, PATHINFO_FILENAME);
    }

    public function extension(): string
    {
        return strtolower(pathinfo($this->path, PATHINFO_EXTENSION));
    }

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function size(): int
    {
        $size = filesize($this->path);

        return $size === false ? 0 : $size;
    }

    public function modified(): ?int
    {
        $mtime = filemtime($this->path);

        return $mtime === false ? null : $mtime;
    }

    public function mimeType(): string
    {
        return self::MIME_TYPES[$this->extension()] ?? 'application/octet-stream';
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mimeType(), 'image/');
    }

    /**
     * A short content hash used to build the immutable, cache-bustable public URL.
     */
    public function hash(): string
    {
        $hash = hash_file('xxh128', $this->path);

        if ($hash === false) {
            throw new RuntimeException(sprintf('Unable to hash file "%s"', $this->path));
        }

        return $hash;
    }

    /**
     * Sidecar metadata (`<file>.json` / `.yaml` / `.yml`), or an empty array when
     * no sidecar exists.
     *
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        if ($this->meta !== null) {
            return $this->meta;
        }

        $sidecar = $this->sidecarPath();
        $data = $sidecar === null ? null : FormatParser::parse($sidecar);

        return $this->meta = is_array($data) ? $data : [];
    }

    /**
     * Path to this file's sidecar (`<file>.json` / `.yaml` / `.yml`). The filename
     * must match exactly; only the extension is case-insensitive (like every other
     * extension in Garner) so an uppercase `.JSON` is not lost on case-sensitive
     * filesystems. Matching the exact basename keeps this in step with how the loader
     * and validator classify the same file. json wins, then yaml, then yml.
     */
    private function sidecarPath(): ?string
    {
        $directory = dirname($this->path);
        $names = scandir($directory);

        if ($names === false) {
            return null;
        }

        $prefix = $this->filename() . '.';
        $found = [];

        foreach ($names as $name) {
            if (!str_starts_with($name, $prefix)) {
                continue;
            }

            $suffix = strtolower(substr($name, strlen($prefix)));

            if (in_array($suffix, ['json', 'yaml', 'yml'], true)) {
                $found[$suffix] = $directory . '/' . $name;
            }
        }

        foreach (['json', 'yaml', 'yml'] as $extension) {
            if (array_key_exists($extension, $found)) {
                return $found[$extension];
            }
        }

        return null;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->meta()[$key] ?? $default;
    }

    /**
     * The file's public URL, publishing it into the public media directory on the
     * first call. Publishing makes the file publicly downloadable by anyone with
     * the URL.
     */
    public function url(): string
    {
        if ($this->publisher === null) {
            throw new RuntimeException(sprintf(
                'Cannot resolve a public URL for "%s" without a media publisher',
                $this->path,
            ));
        }

        return $this->publisher->url($this);
    }
}
