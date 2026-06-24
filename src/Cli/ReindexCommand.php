<?php

declare(strict_types=1);

namespace Garner\Cli;

use Garner\Core\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'reindex', description: 'Rebuild the derived route index')]
final class ReindexCommand extends Command
{
    public function __construct(
        private readonly Application $app,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->app->contentIndex()->rebuild();

        $output->writeln(sprintf(
            'Reindexed %d page(s) into %s',
            $result['count'],
            $result['index_path'],
        ));

        return Command::SUCCESS;
    }
}
