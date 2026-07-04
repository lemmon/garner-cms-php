<?php

declare(strict_types=1);

namespace Garner\Core;

use Symfony\Component\HttpFoundation\File\UploadedFile as HttpFoundationUploadedFile;

/**
 * An uploaded file from a multipart form submission, obtained from
 * Request::file(). A thin facade over HttpFoundation's uploaded file: the
 * client-supplied filename and MIME type are untrusted input, and moveTo()
 * is the one way to accept the upload into a destination of your choosing.
 * Validity and size are captured up front, so they keep describing the
 * submission as received even after moveTo() consumes the temporary file.
 */
final class UploadedFile
{
    private readonly bool $valid;
    private readonly int $size;

    /**
     * @internal constructed by Request::file()
     */
    public function __construct(
        private readonly HttpFoundationUploadedFile $inner,
    ) {
        $this->valid = $inner->isValid();
        $this->size = $this->valid ? (int) $inner->getSize() : 0;
    }

    /**
     * The filename as sent by the client — untrusted input: never use it as a
     * storage path without sanitizing it first.
     */
    public function name(): string
    {
        return $this->inner->getClientOriginalName();
    }

    /**
     * The MIME type as claimed by the client — untrusted input; sniff the
     * moved file's contents when the type matters.
     */
    public function clientMimeType(): string
    {
        return $this->inner->getClientMimeType();
    }

    /**
     * Upload size in bytes (0 when the upload failed).
     */
    public function size(): int
    {
        return $this->size;
    }

    /**
     * Whether the upload completed without a PHP upload error.
     */
    public function valid(): bool
    {
        return $this->valid;
    }

    /**
     * Move the upload into $directory (created when missing) and return the
     * file's new path. $name defaults to the temporary upload name — pass an
     * explicit, safe filename; throws when the upload is invalid or the move
     * fails.
     */
    public function moveTo(string $directory, ?string $name = null): string
    {
        return $this->inner->move($directory, $name)->getPathname();
    }
}
