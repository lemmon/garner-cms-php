<?php

declare(strict_types=1);

namespace Garner\Content;

use Illuminate\Support\Collection;

/**
 * A collection of pages. Extends Illuminate's Collection, so the full query API
 * (filter, reject, where, sortBy, first, take, ...) is available for free, plus
 * the two Garner-specific filters below.
 *
 * @extends Collection<int, Page>
 */
final class PageCollection extends Collection
{
    /**
     * Pages that are not drafts.
     */
    public function published(): self
    {
        return $this->reject(static fn(Page $page): bool => $page->isDraft())->values();
    }

    /**
     * Only draft pages.
     */
    public function drafts(): self
    {
        return $this->filter(static fn(Page $page): bool => $page->isDraft())->values();
    }
}
