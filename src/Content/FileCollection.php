<?php

declare(strict_types=1);

namespace Garner\Content;

use Illuminate\Support\Collection;

/**
 * A collection of page files, keyed by filename. Extends Illuminate's Collection,
 * so the full query API is available for free, plus the media-specific filter below.
 *
 * @extends Collection<string, File>
 */
final class FileCollection extends Collection
{
    /**
     * Only the image files in the collection.
     */
    public function images(): self
    {
        return $this->filter(static fn(File $file): bool => $file->isImage());
    }
}
