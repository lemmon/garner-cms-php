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

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        string $templatesPath,
        string $defaultTemplate = 'default',
        ?MarkdownRenderer $markdownRenderer = null,
        array $options = [],
    ) {
        $this->markdownRenderer = $markdownRenderer ?? new MarkdownRenderer();
        $this->twig = new Environment(
            new FilesystemLoader($templatesPath),
            $this->normalizeOptions($options),
        );
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
        $this->twig->addExtension(new TwigDumpExtension());
    }

    public function renderNotFound(Site $site, Pages $pages, string $path): string
    {
        return $this->renderError($site, $pages, 404, 'not_found', [
            'path' => $path,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function renderError(
        Site $site,
        Pages $pages,
        int $status,
        string $kind,
        array $data = [],
    ): string {
        $template = $this->templateResolver->resolveErrorTemplate();
        $error = [
            'kind' => $kind,
            'path' => is_string($data['path'] ?? null) ? $data['path'] : null,
            'status' => $status,
            'title' => is_string($data['error_title'] ?? null)
                ? $data['error_title']
                : $this->defaultErrorTitle($status, $kind),
        ];

        if ($template === null) {
            return sprintf(
                '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>%1$s | %2$s</title></head><body><main><h1>%1$s</h1><p>%3$s</p></main></body></html>',
                htmlspecialchars((string) $error['title'], ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($site->title(), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($this->defaultErrorMessage($kind), ENT_QUOTES, 'UTF-8'),
            );
        }

        return $this->twig->render($template, [
            ...$data,
            'error' => $error,
            'pages' => $pages,
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

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function normalizeOptions(array $options): array
    {
        $normalized = [];

        foreach (['cache', 'auto_reload', 'charset', 'debug', 'strict_variables'] as $key) {
            if (!array_key_exists($key, $options)) {
                continue;
            }

            $normalized[$key] = $options[$key];
        }

        $cache = $normalized['cache'] ?? false;

        if (is_string($cache) && $cache !== '' && !is_dir($cache)) {
            mkdir($cache, 0o777, true);
        }

        return $normalized;
    }

    private function defaultErrorMessage(string $kind): string
    {
        return $kind === 'not_found'
            ? 'No page matched the requested path.'
            : 'The request could not be completed.';
    }

    private function defaultErrorTitle(int $status, string $kind): string
    {
        return $kind === 'not_found' || $status === 404 ? 'Not Found' : 'Application Error';
    }
}
