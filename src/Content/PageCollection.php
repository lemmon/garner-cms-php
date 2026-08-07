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
     * Pages that are routable and listed — not hidden, meaning neither this page
     * nor any ancestor of it is a draft. Uses the cascaded isHidden() flag, not
     * isDraft(), so a page nested under a draft ancestor is excluded here too,
     * even when its own `draft` is false.
     */
    public function published(): self
    {
        return $this->reject(static fn(Page $page): bool => $page->isHidden())->values();
    }

    /**
     * Pages that are hidden — this page is a draft, or an ancestor of it is.
     */
    public function drafts(): self
    {
        return $this->filter(static fn(Page $page): bool => $page->isHidden())->values();
    }
}
