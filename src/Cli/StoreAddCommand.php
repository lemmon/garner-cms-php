<?php

declare(strict_types=1);

namespace Garner\Cli;

use Garner\Core\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'store:add',
    description: 'Store a JSON value under a key, only if it is not already present',
)]
final class StoreAddCommand extends Command
{
    use ParsesStoreArguments;

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
        return $this->withStoreKeyAndValue($input, $output, function (
            string $key,
            mixed $value,
        ) use ($output): int {
            $escapedKey = $this->escapeStoreKey($key);

            // add() is the atomic insert-if-absent primitive: the whole
            // point is that a caller wanting uniqueness semantics doesn't
            // have to shell out to store:get first and race itself, so the
            // failure exit code alone must carry "already present" — no
            // follow-up read.
            if (!$this->app->store()->add($key, $value)) {
                $output->writeln(sprintf('<error>"%s" already exists.</error>', $escapedKey));

                return Command::FAILURE;
            }

            $output->writeln(sprintf('Added "%s".', $escapedKey));

            return Command::SUCCESS;
        });
    }
}
