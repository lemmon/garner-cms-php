<?php

declare(strict_types=1);

namespace Garner\Tests;

use Garner\Content\Page;
use Garner\Content\PageCollection;
use PHPUnit\Framework\TestCase;

/**
 * Page key reads (__get/__isset): what makes PageCollection's string-key
 * helpers resolve against a page's accessors and freeform metadata.
 */
final class PageKeyAccessTest extends TestCase
{
    public function testStringKeySortByOrdersByAccessor(): void
    {
        $sorted = $this->pages()->sortBy('created')->pluck('path')->all();

        self::assertSame(['/b', '/a', '/c'], $sorted);
    }

    public function testStringKeyPluckReadsAccessors(): void
    {
        self::assertSame(['a', 'b', 'c'], $this->pages()->pluck('id')->all());
        self::assertSame(['Alpha', 'Beta', 'Gamma'], $this->pages()->pluck('title')->all());
    }

    public function testStringKeyWhereReadsAccessorsAndMetadata(): void
    {
        self::assertSame(
            ['/b'],
            $this->pages()->where('created', '2023-01-01')->pluck('path')->all(),
        );
        self::assertSame(
            ['/b'],
            $this->pages()->whereStrict('nav', false)->pluck('path')->values()->all(),
        );
    }

    public function testDotPathReadsIntoContent(): void
    {
        self::assertSame(['A body', null, null], $this->pages()->pluck('content.main')->all());
    }

    public function testAccessorShadowsSameNamedMetadataKey(): void
    {
        $page = $this->page('a', ['url' => 'raw-meta-value']);

        self::assertSame('https://example.test/a', data_get($page, 'url'));
        self::assertSame('raw-meta-value', $page->get('url'));
    }

    public function testPrefixedAccessorResolvesUnprefixedKey(): void
    {
        // No `draft`/`hidden` metadata keys: the values come from isDraft()
        // and isHidden(), the same names Twig resolves `page.draft` to.
        $page = $this->page('a', [], draft: true);

        self::assertTrue(data_get($page, 'draft'));
        self::assertTrue(data_get($page, 'hidden'));
    }

    public function testNullOrMissingMetadataIsNotSet(): void
    {
        $page = $this->page('a', ['nav' => false, 'empty' => null]);

        self::assertFalse(data_get($page, 'nav', 'default'));
        self::assertSame('default', data_get($page, 'empty', 'default'));
        self::assertSame('default', data_get($page, 'missing', 'default'));
    }

    public function testKeysNamingMethodsWithParametersAreNeverRead(): void
    {
        // children()/file() take parameters, so neither the method nor a
        // same-named metadata key answers a key read; Twig keeps calling the
        // method itself, with whatever arguments the template passes.
        $page = $this->page('a', ['children' => 'from-meta', 'file' => 'from-meta']);

        self::assertSame('default', data_get($page, 'children', 'default'));
        self::assertSame('default', data_get($page, 'file', 'default'));
        self::assertSame('from-meta', $page->get('file'));
    }

    private function pages(): PageCollection
    {
        return new PageCollection([
            $this->page('a', ['title' => 'Alpha', 'created' => '2024-03-01'], ['main' => 'A body']),
            $this->page('b', ['title' => 'Beta', 'created' => '2023-01-01', 'nav' => false]),
            $this->page('c', ['title' => 'Gamma', 'created' => '2025-01-01']),
        ]);
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $content
     */
    private function page(string $id, array $meta, array $content = [], bool $draft = false): Page
    {
        return new Page(
            id: $id,
            template: 'default',
            path: '/' . $id,
            meta: $meta,
            content: $content,
            dir: sys_get_temp_dir(),
            draft: $draft,
            baseUrl: 'https://example.test',
        );
    }
}
