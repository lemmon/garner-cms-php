<?php

declare(strict_types=1);

namespace Garner\Render;

use RuntimeException;

final class TemplateResolver
{
    public function __construct(
        private readonly string $templatesPath,
        private readonly string $defaultTemplate = 'default',
    ) {}

    /**
     * Pick the template for an error response. A generic `error.twig` (when present)
     * handles every status; the `404.twig` fallback is 404-specific, so it is only
     * used for actual 404s. Other errors with no `error.twig` return null, letting
     * the renderer emit its built-in generic markup instead of 404 wording.
     */
    public function resolveErrorTemplate(int $status): ?string
    {
        if ($this->templateExists('error')) {
            return 'error.twig';
        }

        if ($status === 404 && $this->templateExists('404')) {
            return '404.twig';
        }

        return null;
    }

    public function resolvePageTemplate(?string $template): string
    {
        if ($template !== null && $this->templateExists($template)) {
            return $template . '.twig';
        }

        if ($this->templateExists($this->defaultTemplate)) {
            return $this->defaultTemplate . '.twig';
        }

        throw new RuntimeException(sprintf(
            'Template "%s" not found and default template "%s" is missing in "%s"',
            $template ?? '(none)',
            $this->defaultTemplate,
            $this->templatesPath,
        ));
    }

    private function templateExists(string $template): bool
    {
        return is_file($this->templatesPath . '/' . $template . '.twig');
    }
}
