<?php

declare(strict_types=1);

namespace Garner\Cli;

use Garner\Core\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'store:set', description: 'Store a JSON value under a key (upsert)')]
final class StoreSetCommand extends Command
{
    public function __construct(
        private readonly Application $app,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('key', InputArgument::REQUIRED, 'The key to write');
        $this->addArgument(
            'value',
            InputArgument::REQUIRED,
            'The value, as JSON (e.g. \'{"a":1}\', \'"text"\', 42)',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $key = $input->getArgument('key');

        if (!is_string($key) || $key === '') {
            $output->writeln('<error>Provide a key.</error>');

            return Command::FAILURE;
        }

        $raw = $input->getArgument('value');
        $raw = is_string($raw) ? $raw : '';
        $value = json_decode($raw, true);

        // json_decode() returning null is ambiguous ("null" decodes to null
        // too), so invalidity is judged by the parser's error state.
        if ($value === null && json_last_error() !== JSON_ERROR_NONE) {
            $output->writeln(sprintf(
                '<error>The value is not valid JSON (%s). Quote strings: \'"hello"\'.</error>',
                json_last_error_msg(),
            ));

            return Command::FAILURE;
        }

        $this->app->store()->set($key, $value);

        $output->writeln(sprintf('Stored "%s".', $key));

        return Command::SUCCESS;
    }
}
