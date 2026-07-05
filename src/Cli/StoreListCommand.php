<?php

declare(strict_types=1);

namespace Garner\Cli;

use Garner\Core\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'store:list', description: 'List key-value store items, optionally by prefix')]
final class StoreListCommand extends Command
{
    public function __construct(
        private readonly Application $app,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('prefix', InputArgument::OPTIONAL, 'Only keys starting with this', '');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output as a JSON object');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $prefix = $input->getArgument('prefix');
        $prefix = is_string($prefix) ? $prefix : '';
        $items = $this->app->store()->items($prefix);

        if ($input->getOption('json') === true) {
            // (object) cast so the top level is always the documented
            // object keyed by full key: PHP coerces a numeric store key
            // like "0" to an integer array key, and json_encode() would
            // emit a keyless JSON array for a list-shaped result (and []
            // for an empty one). Only the top level — JSON_FORCE_OBJECT
            // would also mangle stored list *values* into objects.
            // JSON_PRESERVE_ZERO_FRACTION matches the store's own
            // encoding, so a stored 2.0 lists as 2.0, not 2. OUTPUT_RAW
            // because this is machine-readable output: the default
            // formatting pass would strip console-markup-shaped text
            // (e.g. a stored "<error>x</error>") out of valid JSON.
            $output->writeln(
                (string) json_encode(
                    (object) $items->all(),
                    JSON_PRETTY_PRINT
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_PRESERVE_ZERO_FRACTION,
                ),
                OutputInterface::OUTPUT_RAW,
            );

            return Command::SUCCESS;
        }

        if ($items->isEmpty()) {
            $output->writeln(
                $prefix === ''
                    ? 'The store is empty.'
                    : sprintf('No keys start with "%s".', $prefix),
            );

            return Command::SUCCESS;
        }

        foreach ($items as $key => $value) {
            // Escaped, because this line goes through the formatter for
            // its own <info> tag: a key or value containing
            // console-markup-shaped text must display as-is, not be
            // interpreted (or stripped) as styling.
            $output->writeln(sprintf(
                '<info>%s</info>  %s',
                OutputFormatter::escape((string) $key),
                OutputFormatter::escape((string) json_encode(
                    $value,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
                )),
            ));
        }

        $output->writeln(sprintf('<comment>%d item(s)</comment>', $items->count()));

        return Command::SUCCESS;
    }
}
