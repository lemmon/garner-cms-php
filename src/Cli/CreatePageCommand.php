<?php

declare(strict_types=1);

namespace Garner\Cli;

use Garner\Content\EntryFile;
use Garner\Core\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'page:create', description: 'Scaffold a new page directory and +page.json')]
final class CreatePageCommand extends Command
{
    public function __construct(
        private readonly Application $app,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('route', InputArgument::REQUIRED, 'Route path, e.g. blog/hello');
        $this->addOption('title', null, InputOption::VALUE_REQUIRED, 'Page title');
        $this->addOption('template', null, InputOption::VALUE_REQUIRED, 'Template name');
        $this->addOption('draft', null, InputOption::VALUE_NONE, 'Mark the page as a draft');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be created');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = $input->getOption('json') === true;
        $segments = $this->routeSegments($input->getArgument('route'));

        if ($segments === null) {
            return $this->fail($output, $json, 'Invalid route. Use segments like "blog/hello".');
        }

        $route = implode('/', $segments);
        $dir = $this->app->projectPath('routes') . '/' . $route;

        foreach (EntryFile::CANDIDATES as $candidate) {
            if (is_file($dir . '/' . $candidate)) {
                return $this->fail(
                    $output,
                    $json,
                    sprintf('A page already exists at "/%s".', $route),
                );
            }
        }

        $id = $this->app->idGenerator()->generate();
        $meta = ['id' => $id];

        $title = $input->getOption('title');
        if (is_string($title)) {
            $meta['title'] = $title;
        }

        $template = $input->getOption('template');
        if (is_string($template)) {
            $meta['template'] = $template;
        }

        if ($input->getOption('draft') === true) {
            $meta['draft'] = true;
        }

        $meta['created'] = gmdate('c');

        $entryPath = $dir . '/+page.json';
        $display = ltrim(substr($entryPath, strlen($this->app->rootPath())), '/');

        if ($input->getOption('dry-run') === true) {
            return $this->report($output, $json, 'Would create', $route, $display, $id, true);
        }

        if (!is_dir($dir) && !mkdir($dir, 0o777, true) && !is_dir($dir)) {
            return $this->fail($output, $json, sprintf('Unable to create directory "%s".', $dir));
        }

        $encoded = json_encode(
            $meta,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        if (!is_string($encoded) || file_put_contents($entryPath, $encoded . "\n") === false) {
            return $this->fail($output, $json, sprintf('Unable to write "%s".', $entryPath));
        }

        return $this->report($output, $json, 'Created', $route, $display, $id, false);
    }

    /**
     * @return list<string>|null
     */
    private function routeSegments(mixed $route): ?array
    {
        if (!is_string($route)) {
            return null;
        }

        $segments = array_values(array_filter(
            explode('/', trim($route, '/')),
            static fn(string $segment): bool => $segment !== '',
        ));

        if ($segments === []) {
            return null;
        }

        foreach ($segments as $segment) {
            $safe =
                preg_match('/^[A-Za-z0-9._-]+$/', $segment) === 1
                && !str_starts_with($segment, '.')
                && !str_starts_with($segment, '+');

            if (!$safe) {
                return null;
            }
        }

        return $segments;
    }

    private function report(
        OutputInterface $output,
        bool $json,
        string $verb,
        string $route,
        string $display,
        string $id,
        bool $dryRun,
    ): int {
        if ($json) {
            $output->writeln((string) json_encode([
                'created' => !$dryRun,
                'dryRun' => $dryRun,
                'route' => '/' . $route,
                'path' => $display,
                'id' => $id,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $output->writeln(sprintf('%s %s (id: %s)', $verb, $display, $id));

        return Command::SUCCESS;
    }

    private function fail(OutputInterface $output, bool $json, string $message): int
    {
        if ($json) {
            $output->writeln((string) json_encode([
                'created' => false,
                'error' => $message,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::FAILURE;
        }

        $output->writeln('<error>' . $message . '</error>');

        return Command::FAILURE;
    }
}
