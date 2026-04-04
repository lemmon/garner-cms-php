<?php

declare(strict_types=1);

use Garner\Site\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

final class MarkdownRendererTest extends TestCase
{
    public function testMarkdownRendererRendersMarkdownAndAttributes(): void
    {
        $renderer = new MarkdownRenderer();

        $html = $renderer->render("**Bold**\n\n[About](/about){class=\"inline-link\"}");

        self::assertStringContainsString('<strong>Bold</strong>', $html);
        self::assertStringContainsString('href="/about"', $html);
        self::assertStringContainsString('class="inline-link"', $html);
    }

    public function testMarkdownRendererStripsRawHtmlAndUnsafeLinks(): void
    {
        $renderer = new MarkdownRenderer();

        $html = $renderer->render('Hello <script>alert(1)</script><em>ok</em>');
        $unsafeLink = $renderer->render('[X](javascript:alert(1))');

        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<em>', $html);
        self::assertStringContainsString('Hello alert(1)ok', $html);

        self::assertStringContainsString('<a>X</a>', $unsafeLink);
        self::assertStringNotContainsString('href=', $unsafeLink);
    }
}
