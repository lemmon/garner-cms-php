<?php

declare(strict_types=1);

namespace Garner\Site;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\Markup;
use Twig\TwigFilter;

final class TwigRenderer implements RendererInterface
{
    private readonly MarkdownRenderer $markdownRenderer;
    private readonly Environment $twig;
    private readonly TemplateResolver $templateResolver;

    public function __construct(
        string $templatesPath,
        string $defaultTemplate = 'default',
        ?MarkdownRenderer $markdownRenderer = null,
    ) {
        $this->markdownRenderer = $markdownRenderer ?? new MarkdownRenderer();
        $this->twig = new Environment(new FilesystemLoader($templatesPath));
        $this->templateResolver = new TemplateResolver(
            templatesPath: $templatesPath,
            defaultTemplate: $defaultTemplate,
        );
        $this->twig->addFilter(new TwigFilter(
            'markdown',
            fn(mixed $value): Markup => new Markup(
                $this->markdownRenderer->render($value),
                'UTF-8',
            ),
            [
                'is_safe' => ['html'],
            ],
        ));
    }

    public function renderNotFound(Site $site, Pages $pages, string $path): string
    {
        $template = $this->templateResolver->resolveErrorTemplate();

        if ($template === null) {
            return sprintf(
                '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Not Found | %1$s</title></head><body><main><h1>Not Found</h1><p>No page matched <code>%2$s</code>.</p></main></body></html>',
                htmlspecialchars($site->title(), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($path, ENT_QUOTES, 'UTF-8'),
            );
        }

        return $this->twig->render($template, [
            'pages' => $pages,
            'path' => $path,
            'site' => $site,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function renderPage(Page $page, Site $site, Pages $pages, array $data = []): string
    {
        return $this->twig->render(
            $this->templateResolver->resolvePageTemplate($page->template()),
            [
                ...$data,
                'page' => $page,
                'pages' => $pages,
                'site' => $site,
            ],
        );
    }
}
