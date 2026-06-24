<?php

declare(strict_types=1);

namespace Garner\Render;

use Garner\Content\Page;
use Garner\Content\Site;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\Markup;
use Twig\TwigFilter;

final class TwigRenderer implements RendererInterface
{
    private readonly FilesystemLoader $loader;
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
        $this->loader = new FilesystemLoader($templatesPath);
        $this->markdownRenderer = $markdownRenderer ?? new MarkdownRenderer();
        $this->twig = new Environment($this->loader, $this->normalizeOptions($options));
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
            ['is_safe' => ['html']],
        ));
        $this->twig->addExtension(new TwigDumpExtension());
    }

    /**
     * @param array<string, mixed> $data
     */
    public function renderPage(Page $page, Site $site, array $data = []): string
    {
        return $this->twig->render($this->resolveTemplate($page), [
            ...$data,
            'page' => $page,
            'site' => $site,
            'content' => $page->content(),
            'meta' => $page->meta(),
        ]);
    }

    /**
     * A co-located +template.twig overrides the template field. It is mounted
     * under a per-directory Twig namespace so it can {% extends %}/{% include %}
     * shared site/templates and so distinct pages never collide in one process.
     */
    private function resolveTemplate(Page $page): string
    {
        $templateFile = $page->templateFile();

        if ($templateFile === null) {
            return $this->templateResolver->resolvePageTemplate($page->template());
        }

        $directory = dirname($templateFile);
        $namespace = 'page_' . substr(sha1($directory), 0, 16);
        $this->loader->setPaths([$directory], $namespace);

        return '@' . $namespace . '/' . basename($templateFile);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function renderError(Site $site, int $status, string $kind, array $data = []): string
    {
        $template = $this->templateResolver->resolveErrorTemplate($status);
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
                '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>%1$s | %2$s</title>'
                . '</head><body><main><h1>%1$s</h1><p>%3$s</p></main></body></html>',
                htmlspecialchars((string) $error['title'], ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($site->title(), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($this->defaultErrorMessage($kind), ENT_QUOTES, 'UTF-8'),
            );
        }

        return $this->twig->render($template, [
            ...$data,
            'error' => $error,
            'site' => $site,
        ]);
    }

    public function renderNotFound(Site $site, string $path): string
    {
        return $this->renderError($site, 404, 'not_found', ['path' => $path]);
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
