<?php

declare(strict_types=1);

namespace Garner\Tests;

use Garner\Support\Text;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Twig\Markup;

final class TextTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function values(): iterable
    {
        yield 'string' => ['text', 'text'];
        yield 'int' => [42, '42'];
        yield 'float' => [1.5, '1.5'];
        yield 'true' => [true, 'true'];
        yield 'false' => [false, 'false'];
        yield 'stringable' => [new Markup('<b>x</b>', 'UTF-8'), '<b>x</b>'];
        yield 'null' => [null, ''];
        yield 'array' => [['a'], ''];
        yield 'plain object' => [new stdClass(), ''];
    }

    #[DataProvider('values')]
    public function testFrom(mixed $value, string $expected): void
    {
        self::assertSame($expected, Text::from($value));
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function excerpts(): iterable
    {
        yield 'fits, returned whole' => ['Short text.', 160, 'Short text.'];
        yield 'exactly the limit' => ['one two', 7, 'one two'];
        yield 'cut at a word boundary' => ['one two three four', 9, 'one two…'];
        yield 'last word fits exactly' => ['one two three', 8, 'one two…'];
        yield 'word before a comma fits' => ['one two, three four', 8, 'one two…'];
        yield 'word before a period fits' => [
            'We ship fast. Then we test it all',
            13,
            'We ship fast…',
        ];
        yield 'decimal point is not a boundary' => [
            'Prices rose by 1.5 million last year',
            17,
            'Prices rose by…',
        ];
        yield 'thousands separator is not a boundary' => [
            'Revenue was 1,250,000 dollars in total',
            16,
            'Revenue was…',
        ];
        yield 'version number is not a boundary' => [
            'Upgrade to version 2.10.7 today',
            22,
            'Upgrade to version…',
        ];
        yield 'existing ellipsis not doubled' => ['Wait… then we went home', 8, 'Wait…'];
        yield 'invisible characters dropped' => [
            "Soft\u{00AD}ware \u{200B}\u{FEFF}is\u{2060} here",
            160,
            'Software is here',
        ];
        yield 'single long word cut mid-word' => ['Supercalifragilistic word', 10, 'Supercali…'];
        yield 'long token after the first word cut mid-word' => [
            'See https://example.com/a/very/long/path/that/goes/on and more',
            20,
            'See https://example…',
        ];
        yield 'multibyte counted as characters' => [
            'Žluťoučký kůň úpěl ďábelské ódy',
            14,
            'Žluťoučký kůň…',
        ];
        yield 'trailing punctuation dropped' => ['First, second — third', 16, 'First, second…'];
        yield 'only punctuation before the cut' => ['— — — — wordword', 6, '— — —…'];
        yield 'multibyte last letter kept intact' => ['Ŕ Ŕ Ŕ Ŕ', 4, 'Ŕ Ŕ…'];
        yield 'plain text keeps < and &' => [
            'Kids <12 eat free &amp; more',
            160,
            'Kids <12 eat free &amp; more',
        ];
        yield 'whitespace and nbsp collapsed' => ["a \n\t b\u{00A0}\u{00A0}c", 160, 'a b c'];
        yield 'empty' => ['', 5, ''];
    }

    #[DataProvider('excerpts')]
    public function testExcerpt(string $text, int $length, string $expected): void
    {
        $excerpt = Text::excerpt($text, $length);

        self::assertSame($expected, $excerpt);
        self::assertLessThanOrEqual($length, mb_strlen($excerpt));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function htmlExcerpts(): iterable
    {
        yield 'tags stripped, entities decoded' => [
            "<p>We <strong>ship</strong> fast &amp; &quot;well&quot;.</p>\n<p>More.</p>",
            'We ship fast & "well". More.',
        ];
        yield 'CommonMark block and line breaks keep word boundaries' => [
            "<h2>Title</h2>\n<p>Hello<br />\nworld</p>\n<ul>\n<li>one</li>\n<li>two</li>\n</ul>",
            'Title Hello world one two',
        ];
        yield 'inline tags add no space' => [
            '<p><strong>Bold</strong>, <em>then</em>.</p>',
            'Bold, then.',
        ];
    }

    #[DataProvider('htmlExcerpts')]
    public function testExcerptOfHtml(string $html, string $expected): void
    {
        self::assertSame($expected, Text::excerpt($html, 160, html: true));
    }

    public function testExcerptWithCustomEllipsis(): void
    {
        self::assertSame('one two...', Text::excerpt('one two three four', 11, '...'));
        self::assertSame('one two', Text::excerpt('one two three', 7, ''));
        // Punctuation that ends the text counts as followed by a space.
        self::assertSame('one two', Text::excerpt('one two.', 7, ''));
        // No room for any text beside it: the ellipsis is dropped, not
        // allowed past the limit.
        self::assertSame('He', Text::excerpt('Hello world', 2, '...'));
        self::assertSame('Hel', Text::excerpt('Hello world', 3, '...'));
        self::assertSame('H…', Text::excerpt('Hello world', 2));
    }

    public function testExcerptCountsUtf8WhateverTheInternalEncoding(): void
    {
        $previous = mb_internal_encoding();
        mb_internal_encoding('ISO-8859-1');

        try {
            self::assertSame('Ŕ Ŕ…', Text::excerpt('Ŕ Ŕ Ŕ Ŕ', 4));
        } finally {
            mb_internal_encoding($previous);
        }
    }

    public function testPlainReadsHtmlAsVisibleText(): void
    {
        self::assertSame('a & b c', Text::plain("<p>a &amp;\u{00A0}b</p>\n<p>c</p>"));
        self::assertSame('', Text::plain("<p>&nbsp;<img src=\"/h.jpg\" alt=\"\"> \u{00A0}</p>"));
    }

    public function testExcerptRejectsInvalidUtf8(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('excerpt text and ellipsis must be valid UTF-8');

        Text::excerpt("Caf\xE9 au lait", 5);
    }

    public function testExcerptLengthMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('excerpt length must be at least 1, got 0');

        Text::excerpt('text', 0);
    }
}
