<?php

declare(strict_types=1);

namespace Garner\Cli;

use Garner\Core\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'store:get', description: 'Print a key-value store item as JSON')]
final class StoreGetCommand extends Command
{
    public function __construct(
        private readonly Application $app,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('key', InputArgument::REQUIRED, 'The key to read');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $key = $input->getArgument('key');

        if (!is_string($key) || $key === '') {
            $output->writeln('<error>Provide a key.</error>');

            return Command::FAILURE;
        }

        $store = $this->app->store();

        // A stored null and a missing key both get() as null; only one of
        // them should print "null" and succeed.
        if (!$store->has($key)) {
            $output->writeln(sprintf('<error>No value stored under "%s".</error>', $key));

            return Command::FAILURE;
        }

        // JSON_PRESERVE_ZERO_FRACTION matches the store's own encoding:
        // a stored 2.0 prints as 2.0, so piping the output back through
        // store:set doesn't silently turn a float into an int.
        // OUTPUT_RAW because this is machine-readable output: the default
        // formatting pass would strip console-markup-shaped text (e.g. a
        // stored "<error>x</error>") out of valid JSON.
        $output->writeln(
            (string) json_encode(
                $store->get($key),
                JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION,
            ),
            OutputInterface::OUTPUT_RAW,
        );

        return Command::SUCCESS;
    }
}
