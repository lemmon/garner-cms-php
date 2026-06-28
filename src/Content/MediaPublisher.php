<?php

declare(strict_types=1);

namespace Garner\Content;

use RuntimeException;

/**
 * Publishes page-owned files into the public media directory and resolves their
 * public URLs. Content files live outside the web root (under `routes/`), so a
 * file is materialised into `public/media/<hash>/<filename>` the first time its
 * URL is requested; the web server then serves it directly.
 *
 * The path embeds a short content hash, which makes the URL immutable and
 * cache-bustable: editing the file changes the hash, so the URL changes too. The
 * published file is a copied snapshot (not a symlink to the live source), so a hash
 * URL keeps serving exactly the bytes that produced it even after the source is
 * edited, moved, or deleted. Publishing a file makes it publicly downloadable by
 * anyone holding the URL — private files should be streamed through a controller
 * instead, never published.
 */
final class MediaPublisher
{
    public function __construct(
        private readonly string $publicPath,
        private readonly string $urlPrefix = '/media',
    ) {}

    /**
     * Publish a file (idempotently) and return its public URL.
     */
    public function url(File $file): string
    {
        $hash = $file->hash();
        $filename = $file->filename();
        $target = $this->mediaRoot() . '/' . $hash . '/' . $filename;

        if (!is_file($target)) {
            // publish() returns the hash of the bytes it actually wrote, which may
            // differ from the source hash above if the source changed mid-request.
            $hash = $this->publish($file->path(), $filename);
        }

        return $this->urlPrefix . '/' . $hash . '/' . rawurlencode($filename);
    }

    private function mediaRoot(): string
    {
        return $this->publicPath . $this->urlPrefix;
    }

    /**
     * Copy the source to a temporary snapshot, hash *that snapshot*, and atomically
     * move it into <media>/<hash>/<filename>. Hashing the copied bytes rather than the
     * live source guarantees the hash in the URL always matches the published bytes,
     * even if the source is edited mid-request — so any file at <media>/<H>/name holds
     * bytes whose hash is H. Returns the snapshot's hash.
     *
     * A snapshot copy (not a symlink to the live source) also keeps the published file
     * serving its exact bytes after the source changes, and avoids the symlink()
     * warning the error handler would turn into a 500 on hosts that disallow symlinks.
     */
    private function publish(string $source, string $filename): string
    {
        $mediaRoot = $this->mediaRoot();
        $this->ensureDirectory($mediaRoot);

        $tmp = $mediaRoot . '/.' . bin2hex(random_bytes(8)) . '.tmp';

        if (!copy($source, $tmp)) {
            $this->discard($tmp);

            throw new RuntimeException(sprintf('Unable to publish media file "%s"', $source));
        }

        $hash = hash_file('xxh128', $tmp);

        if ($hash === false) {
            $this->discard($tmp);

            throw new RuntimeException(sprintf('Unable to hash media file "%s"', $source));
        }

        $target = $mediaRoot . '/' . $hash . '/' . $filename;

        if (is_file($target)) {
            // Identical content is already published; drop the redundant snapshot.
            $this->discard($tmp);

            return $hash;
        }

        $this->ensureDirectory($mediaRoot . '/' . $hash);

        // rename() atomically publishes the snapshot, so a request never sees a
        // half-written file.
        if (!rename($tmp, $target)) {
            $this->discard($tmp);

            throw new RuntimeException(sprintf('Unable to publish media file to "%s"', $target));
        }

        return $hash;
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf(
                'Unable to create media directory "%s"',
                $directory,
            ));
        }
    }

    private function discard(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
