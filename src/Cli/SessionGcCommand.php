<?php

declare(strict_types=1);

namespace Garner\Cli;

use Garner\Core\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'session:gc', description: 'Sweep expired session data')]
final class SessionGcCommand extends Command
{
    public function __construct(
        private readonly Application $app,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->app->sessionStore()->gc();

        $output->writeln('Swept expired sessions.');

        return Command::SUCCESS;
    }
}
