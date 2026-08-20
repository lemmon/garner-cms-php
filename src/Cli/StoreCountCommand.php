<?php

declare(strict_types=1);

namespace Garner\Cli;

use Garner\Core\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'store:count', description: 'Count key-value store items, optionally by prefix')]
final class StoreCountCommand extends Command
{
    use ParsesStoreArguments;

    public function __construct(
        private readonly Application $app,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('prefix', InputArgument::OPTIONAL, 'Only keys starting with this', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $prefix = $this->storePrefix($input);

        // A bare number, deliberately undecorated: the documented cheap path
        // from the shell (`n=$(php bin/garner store:count email:)`) is the
        // whole reason this command exists over `store:list --json | jq
        // length`, which loads every value just to count them.
        $output->writeln((string) $this->app->store()->count($prefix));

        return Command::SUCCESS;
    }
}
