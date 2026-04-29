<?php

declare(strict_types=1);

use Garner\Support\Slug;
use PHPUnit\Framework\TestCase;

final class SlugTest extends TestCase
{
    public function testNormalizeTreatsPathSeparatorsAsWordSeparators(): void
    {
        self::assertSame('about-me', Slug::normalize('about/me'));
        self::assertSame('about-me', Slug::normalize('about\\me'));
    }

    public function testNormalizeTreatsPunctuationAsWordSeparators(): void
    {
        self::assertSame('about-me', Slug::normalize('ABOUT!ME'));
        self::assertSame('about-me', Slug::normalize('ABOUT=ME'));
    }

    public function testNormalizeTransliteratesAccentedLettersToAscii(): void
    {
        self::assertSame('zlutoucky-kun-42', Slug::normalize('Žluťoučký=kůň 42'));
        self::assertSame('cesky-krumlov-2026', Slug::normalize('Český Krumlov 2026'));
    }

    public function testNormalizeTrimsSeparatorRuns(): void
    {
        self::assertSame('company-name', Slug::normalize('  Company  Name!!  '));
    }
}
