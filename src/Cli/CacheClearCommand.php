<?php

declare(strict_types=1);

namespace Garner\Cli;

use Garner\Core\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(name: 'cache:clear', description: 'Delete the compiled template cache')]
final class CacheClearCommand extends Command
{
    public function __construct(
        private readonly Application $app,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $this->app->twigCachePath();

        if (!is_dir($path)) {
            $output->writeln(sprintf('Template cache already clear (%s)', $path));

            return Command::SUCCESS;
        }

        new Filesystem()->remove($path);

        $output->writeln(sprintf('Cleared template cache %s', $path));

        return Command::SUCCESS;
    }
}
