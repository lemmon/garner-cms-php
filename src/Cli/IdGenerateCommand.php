<?php

declare(strict_types=1);

namespace Garner\Cli;

use Garner\Core\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'id:generate',
    description: 'Emit one or more ids from the project\'s configured id generator',
)]
final class IdGenerateCommand extends Command
{
    /**
     * A generous ceiling for a single invocation — far past any real
     * scaffolding batch (seeding a few store entries, fresh ids for a copied
     * subtree) but small enough that the generation loop and JSON encoding
     * below stay near-instant. Without a cap, an all-digit --count larger
     * than PHP_INT_MAX still passes ctype_digit() and casts (int) to
     * PHP_INT_MAX rather than erroring, turning a typo into an effectively
     * unbounded loop.
     */
    private const int MAX_COUNT = 10_000;

    public function __construct(
        private readonly Application $app,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'count',
            null,
            InputOption::VALUE_REQUIRED,
            sprintf('How many ids to generate (max %d)', self::MAX_COUNT),
            '1',
        );
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output as a JSON array');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = $input->getOption('json') === true;
        $rawCount = $input->getOption('count');
        $count = is_string($rawCount) && ctype_digit($rawCount) ? (int) $rawCount : 0;

        if ($count < 1 || $count > self::MAX_COUNT) {
            return $this->fail(
                $output,
                $json,
                sprintf(
                    'Option --count must be a positive integer no greater than %d.',
                    self::MAX_COUNT,
                ),
            );
        }

        $generator = $this->app->idGenerator();
        $ids = [];

        for ($i = 0; $i < $count; $i++) {
            $ids[] = $generator->generate();
        }

        if ($json) {
            // OUTPUT_RAW: this is machine-readable output, and the default
            // formatting pass would strip console-markup-shaped text out of
            // valid JSON. Only built-in generators ship with the project,
            // but `app.ids.generator` accepts a custom class or callable
            // (README), so a generated id is project-configured content, not
            // a value this command can assume is markup-safe — including
            // being valid UTF-8, which json_encode() requires and silently
            // fails (returns false) without.
            $encoded = json_encode($ids, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            if ($encoded === false) {
                return $this->fail(
                    $output,
                    true,
                    sprintf(
                        'Generated id(s) could not be encoded as JSON: %s',
                        json_last_error_msg(),
                    ),
                );
            }

            $output->writeln($encoded, OutputInterface::OUTPUT_RAW);

            return Command::SUCCESS;
        }

        foreach ($ids as $id) {
            $output->writeln(OutputFormatter::escape($id));
        }

        return Command::SUCCESS;
    }

    private function fail(OutputInterface $output, bool $json, string $message): int
    {
        if ($json) {
            $output->writeln(
                (string) json_encode([
                    'error' => $message,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                OutputInterface::OUTPUT_RAW,
            );

            return Command::FAILURE;
        }

        $output->writeln('<error>' . $message . '</error>');

        return Command::FAILURE;
    }
}
