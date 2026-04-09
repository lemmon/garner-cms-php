<?php

declare(strict_types=1);

namespace Garner\Site;

use RuntimeException;

final class TemplateResolver
{
    public function __construct(
        private readonly string $templatesPath,
        private readonly string $defaultTemplate = 'default',
    ) {}

    public function resolveErrorTemplate(): ?string
    {
        if ($this->templateExists('error')) {
            return 'error.twig';
        }

        return $this->templateExists('404') ? '404.twig' : null;
    }

    public function resolvePageTemplate(string $template): string
    {
        if ($this->templateExists($template)) {
            return $template . '.twig';
        }

        if ($this->templateExists($this->defaultTemplate)) {
            return $this->defaultTemplate . '.twig';
        }

        throw new RuntimeException(sprintf(
            'Template "%s" not found and default template "%s" is missing in "%s"',
            $template,
            $this->defaultTemplate,
            $this->templatesPath,
        ));
    }

    private function templateExists(string $template): bool
    {
        return is_file($this->templatesPath . '/' . $template . '.twig');
    }
}
