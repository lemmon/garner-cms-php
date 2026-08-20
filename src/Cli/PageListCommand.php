<?php

declare(strict_types=1);

namespace Garner\Cli;

use Garner\Content\RoutePath;
use Garner\Core\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'page:list', description: 'List the page tree, optionally scoped to a subtree')]
final class PageListCommand extends Command
{
    use WritesCliError;

    public function __construct(
        private readonly Application $app,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'path',
            InputArgument::OPTIONAL,
            'Route path to scope the listing to, e.g. blog (default: whole tree)',
        );
        $this->addOption(
            'drafts',
            null,
            InputOption::VALUE_NONE,
            'Include drafts and pages hidden by a draft ancestor',
        );
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output as a JSON array');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = $input->getOption('json') === true;
        $drafts = $input->getOption('drafts') === true;
        $rawPath = $input->getArgument('path');
        $root = RoutePath::normalize(is_string($rawPath) ? $rawPath : '/');

        // Reads the derived index directly rather than $app->pages() — the
        // listing only ever needs path/id/title/draft/hidden, every one of
        // them already a column on the index (see ContentIndex::pageRow()).
        // Hydrating full Page objects here would parse every listed page's
        // sibling content files for data never displayed, making a
        // whole-tree listing's cost (and failure surface, on one malformed
        // content file anywhere in the tree) scale with total site content
        // instead of with the index read it actually is.
        $index = $this->app->contentIndex();
        $rootRow = $index->listingRowForPath($root, $drafts);
        $descendants = $index->listingDescendants($root, $drafts);
        $rows = $rootRow !== null ? [$rootRow, ...$descendants] : $descendants;

        if ($json) {
            $encoded = json_encode(
                $rows,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );

            if ($encoded === false) {
                return $this->fail(
                    $output,
                    true,
                    sprintf('Listing could not be encoded as JSON: %s', json_last_error_msg()),
                );
            }

            $output->writeln($encoded, OutputInterface::OUTPUT_RAW);

            return Command::SUCCESS;
        }

        if ($rows === []) {
            $output->writeln(sprintf('No pages found under "%s".', OutputFormatter::escape($root)));

            return Command::SUCCESS;
        }

        foreach ($rows as $row) {
            $output->writeln(sprintf(
                '<info>%s</info>  %s%s  %s',
                OutputFormatter::escape($row['path']),
                OutputFormatter::escape($row['id']),
                $this->statusTag($row),
                OutputFormatter::escape($row['title'] ?? '(untitled)'),
            ));
        }

        $output->writeln(sprintf('<comment>%d page(s)</comment>', count($rows)));

        return Command::SUCCESS;
    }

    /**
     * " [draft]" for a page's own flag, " [hidden]" when it's only hidden by
     * cascade from a draft ancestor (own draft false but hidden true — see
     * Page::isHidden()), otherwise nothing. The two never both apply: hidden
     * is true whenever draft is.
     *
     * @param array{path: string, id: string, title: string|null, draft: bool, hidden: bool} $row
     */
    private function statusTag(array $row): string
    {
        if ($row['draft']) {
            return ' <comment>[draft]</comment>';
        }

        if ($row['hidden']) {
            return ' <comment>[hidden]</comment>';
        }

        return '';
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

        $this->writeCliError($output, $message);

        return Command::FAILURE;
    }
}
