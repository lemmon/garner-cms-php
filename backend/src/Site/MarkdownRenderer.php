<?php

declare(strict_types=1);

namespace Garner\Site;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Attributes\AttributesExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Util\HtmlFilter;

final class MarkdownRenderer
{
    private readonly MarkdownConverter $converter;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        $environment = new Environment([
            'allow_unsafe_links' => false,
            'html_input' => HtmlFilter::STRIP,
            ...$config,
        ]);

        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());
        $environment->addExtension(new AttributesExtension());

        $this->converter = new MarkdownConverter($environment);
    }

    public function render(mixed $value): string
    {
        $markdown = $this->normalize($value);

        if ($markdown === '') {
            return '';
        }

        return (string) $this->converter->convert($markdown);
    }

    private function normalize(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        return '';
    }
}
