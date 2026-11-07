<?php

declare(strict_types=1);

namespace Garner\Render;

use Garner\Support\Text;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFilter;

/**
 * Garner's text filters: `markdown`, `first_paragraph`, `excerpt`.
 *
 * An extension rather than `$twig->addFilter()`, so a site's own filter of
 * the same name (from `app/twig.php`, added later) takes precedence instead
 * of failing as a duplicate.
 */
final class TwigTextExtension extends AbstractExtension
{
    public function __construct(
        private readonly MarkdownRenderer $markdownRenderer,
    ) {}

    public function getFilters(): array
    {
        return [
            new TwigFilter(
                'markdown',
                fn(mixed $value): Markup => new Markup(
                    $this->markdownRenderer->render($value),
                    'UTF-8',
                ),
                ['is_safe' => ['html']],
            ),
            new TwigFilter(
                'first_paragraph',
                fn(mixed $value): Markup => new Markup(
                    $this->markdownRenderer->firstParagraph($value),
                    'UTF-8',
                ),
                ['is_safe' => ['html']],
            ),
            // Plain text out, escaped as usual. Input is read as HTML: Twig
            // escapes anything it doesn't know to be safe before the filter
            // sees it (pre_escape), so a plain `Kids <12` arrives as
            // `Kids &lt;12` and decodes back, while `markdown` output, `|raw`
            // and other safe HTML arrive as markup.
            new TwigFilter(
                'excerpt',
                static fn(
                    mixed $value,
                    int $length = 160,
                    string $ellipsis = '…',
                ): string => Text::excerpt(Text::from($value), $length, $ellipsis, html: true),
                ['pre_escape' => 'html'],
            ),
        ];
    }
}
