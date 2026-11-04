<?php

declare(strict_types=1);

namespace Garner\Cli;

use Garner\Core\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

#[AsCommand(name: 'cache:clear', description: 'Clear application and compiled template caches')]
final class CacheClearCommand extends Command
{
    public function __construct(
        private readonly Application $app,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'templates',
            null,
            InputOption::VALUE_NONE,
            'Clear only the compiled template cache',
        );
        $this->addOption('app', null, InputOption::VALUE_NONE, 'Clear only the application cache');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // No flag clears both layers. A flag narrows to its layer; both flags
        // together are the same as none.
        $templates = $input->getOption('templates') === true;
        $application = $input->getOption('app') === true;
        $all = !$templates && !$application;

        // Each layer: where it lives, whether there is anything there, and
        // how to clear it. Checked against the same path clear() acts on
        // (cache()->path(), not a separately recomputed default) so a
        // withCache()-overridden cache is reported and cleared consistently.
        // A layer that isn't selected is never resolved.
        /** @var list<array{label: string, path: string, exists: bool, clear: callable(): bool}> $layers */
        $layers = [];

        if ($all || $application) {
            $cache = $this->app->cache();
            $layers[] = [
                'label' => 'application cache',
                'path' => $cache->path(),
                'exists' => is_file($cache->path()) || is_link($cache->path()),
                'clear' => $cache->clear(...),
            ];
        }

        if ($all || $templates) {
            $twigPath = $this->app->twigCachePath();
            $layers[] = [
                'label' => 'template cache',
                'path' => $twigPath,
                'exists' => is_dir($twigPath),
                'clear' => static function () use ($twigPath): bool {
                    new Filesystem()->remove($twigPath);

                    return true;
                },
            ];
        }

        $failed = false;

        foreach ($layers as $layer) {
            if (!$layer['exists']) {
                $output->writeln(sprintf(
                    '%s already clear (%s)',
                    ucfirst($layer['label']),
                    $layer['path'],
                ));

                continue;
            }

            try {
                $cleared = $layer['clear']();
                $output->writeln(
                    $cleared
                        ? sprintf('Cleared %s %s', $layer['label'], $layer['path'])
                        : sprintf(
                            '%s already clear (%s)',
                            ucfirst($layer['label']),
                            $layer['path'],
                        ),
                );
            } catch (Throwable $exception) {
                $failed = true;
                $output->writeln(sprintf(
                    '<error>Unable to clear %s %s: %s</error>',
                    $layer['label'],
                    $layer['path'],
                    $exception->getMessage(),
                ));
            }
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
