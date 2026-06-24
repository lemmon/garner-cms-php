<?php

declare(strict_types=1);

namespace Garner\Cli;

use Garner\Core\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'validate', description: 'Check the content tree for problems')]
final class ValidateCommand extends Command
{
    public function __construct(
        private readonly Application $app,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output results as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $issues = $this->app->treeValidator()->validate();

        if ($input->getOption('json') === true) {
            $output->writeln((string) json_encode([
                'ok' => $issues === [],
                'issues' => array_map(static fn($issue): array => $issue->toArray(), $issues),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $issues === [] ? Command::SUCCESS : Command::FAILURE;
        }

        if ($issues === []) {
            $output->writeln('No problems found.');

            return Command::SUCCESS;
        }

        foreach ($issues as $issue) {
            $output->writeln(sprintf('%s: %s', $issue->path, $issue->message));
        }

        $output->writeln(sprintf('Found %d problem(s).', count($issues)));

        return Command::FAILURE;
    }
}
