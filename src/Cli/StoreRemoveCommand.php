<?php

declare(strict_types=1);

namespace Garner\Cli;

use Garner\Core\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'store:remove', description: 'Delete a key-value store item')]
final class StoreRemoveCommand extends Command
{
    public function __construct(
        private readonly Application $app,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('key', InputArgument::REQUIRED, 'The key to delete');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $key = $input->getArgument('key');

        if (!is_string($key) || $key === '') {
            $output->writeln('<error>Provide a key.</error>');

            return Command::FAILURE;
        }

        $store = $this->app->store();

        if (!$store->has($key)) {
            $output->writeln(sprintf('Nothing stored under "%s".', $key));

            return Command::SUCCESS;
        }

        $store->remove($key);

        $output->writeln(sprintf('Removed "%s".', $key));

        return Command::SUCCESS;
    }
}
