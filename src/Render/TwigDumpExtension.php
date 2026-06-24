<?php

declare(strict_types=1);

namespace Garner\Render;

use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\HtmlDumper;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\Template;
use Twig\TemplateWrapper;
use Twig\TwigFunction;

final class TwigDumpExtension extends AbstractExtension
{
    private readonly VarCloner $cloner;
    private readonly HtmlDumper $dumper;

    public function __construct()
    {
        $this->cloner = new VarCloner();
        $this->dumper = new HtmlDumper();
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction(
                'dump',
                [$this, 'dump'],
                [
                    'is_safe' => ['html'],
                    'needs_context' => true,
                    'needs_environment' => true,
                    'is_variadic' => true,
                ],
            ),
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    public function dump(Environment $env, array $context, mixed ...$vars): ?string
    {
        if (!$env->isDebug()) {
            return null;
        }

        if ($vars === []) {
            $vars = [];

            foreach ($context as $key => $value) {
                if ($value instanceof Template || $value instanceof TemplateWrapper) {
                    continue;
                }

                $vars[$key] = $value;
            }
        }

        $output = '';

        foreach ($vars as $var) {
            $output .= $this->dumper->dump($this->cloner->cloneVar($var), true) ?? '';
        }

        return $output;
    }
}
