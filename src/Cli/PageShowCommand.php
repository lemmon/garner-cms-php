<?php

declare(strict_types=1);

namespace Garner\Cli;

use Garner\Content\EntryFile;
use Garner\Content\FormatParser;
use Garner\Content\Page;
use Garner\Content\RoutePath;
use Garner\Core\Application;
use Garner\Render\TemplateResolver;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'page:show', description: 'Inspect one page by route or stable id')]
final class PageShowCommand extends Command
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
            'page',
            InputArgument::REQUIRED,
            'Route path (e.g. blog/hello) or stable page id',
        );
        $this->addOption(
            'route',
            null,
            InputOption::VALUE_NONE,
            'Treat the argument as a route path only',
        );
        $this->addOption(
            'id',
            null,
            InputOption::VALUE_NONE,
            'Treat the argument as a stable id only',
        );
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = $input->getOption('json') === true;
        $asRoute = $input->getOption('route') === true;
        $asId = $input->getOption('id') === true;
        $argument = $input->getArgument('page');

        if (!is_string($argument) || trim($argument) === '') {
            return $this->fail($output, $json, 'Provide a route path or id.');
        }

        if ($asRoute && $asId) {
            return $this->fail($output, $json, 'Use only one of --route, --id.');
        }

        $page = $this->resolve($output, $json, $argument, $asRoute, $asId);

        if ($page === null) {
            return Command::FAILURE;
        }

        return $page->isEndpoint()
            ? $this->reportEndpoint($output, $json, $page)
            : $this->reportPage($output, $json, $page);
    }

    /**
     * Resolves the argument to a page, or writes a failure and returns null.
     * With neither --route nor --id, both interpretations are tried: a
     * route lookup (which supports drafts) and an id lookup (which only
     * resolves visible pages — findById()'s existing contract, the same one
     * Site::findById() gives templates, so a hidden page must be addressed
     * by route). If both resolve to *different* pages — a real id equal to
     * some other page's route text, e.g. a nested page with id "pricing"
     * while "/pricing" belongs to someone else — silently preferring one
     * would misreport the other, so this asks the caller to disambiguate
     * with --route/--id instead of guessing.
     */
    private function resolve(
        OutputInterface $output,
        bool $json,
        string $argument,
        bool $asRoute,
        bool $asId,
    ): ?Page {
        $pages = $this->app->pages();

        if ($asRoute) {
            $page = $pages->find(RoutePath::normalize($argument), drafts: true);

            return $page ?? $this->failNull(
                $output,
                $json,
                sprintf('No page found for route "%s".', $argument),
            );
        }

        if ($asId) {
            $page = $pages->findById($argument);

            return $page ?? $this->failNull(
                $output,
                $json,
                sprintf('No page found for id "%s".', $argument),
            );
        }

        $byRoute = $pages->find(RoutePath::normalize($argument), drafts: true);
        $byId = $pages->findById($argument);

        if ($byRoute !== null && $byId !== null && $byRoute->path() !== $byId->path()) {
            return $this->failNull(
                $output,
                $json,
                sprintf(
                    '"%s" is ambiguous: it matches the route %s and a different page\'s id'
                    . ' (%s). Use --route or --id to pick one.',
                    $argument,
                    $byRoute->path(),
                    $byId->path(),
                ),
            );
        }

        $page = $byRoute ?? $byId;

        return $page ?? $this->failNull(
            $output,
            $json,
            sprintf('No page found for "%s".', $argument),
        );
    }

    private function failNull(OutputInterface $output, bool $json, string $message): null
    {
        $this->fail($output, $json, $message);

        return null;
    }

    private function reportEndpoint(OutputInterface $output, bool $json, Page $page): int
    {
        $data = [
            'path' => $page->path(),
            'id' => $page->id(),
            'endpoint' => true,
            'controller' => $this->relativePath($page->controllerFile()),
        ];

        if ($json) {
            $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            if ($encoded === false) {
                return $this->fail(
                    $output,
                    true,
                    sprintf('Page data could not be encoded as JSON: %s', json_last_error_msg()),
                );
            }

            $output->writeln($encoded, OutputInterface::OUTPUT_RAW);

            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            '%s — route endpoint (not a page)',
            OutputFormatter::escape($data['path']),
        ));
        $output->writeln(sprintf('id:         %s', OutputFormatter::escape($data['id'])));
        $output->writeln(sprintf(
            'controller: %s',
            OutputFormatter::escape($data['controller'] ?? '(none)'),
        ));

        return Command::SUCCESS;
    }

    private function reportPage(OutputInterface $output, bool $json, Page $page): int
    {
        $dir = $this->app->projectPath('routes') . ($page->path() === '/' ? '' : $page->path());
        $entry = EntryFile::find($dir);
        $content = $this->contentFiles($dir);
        $files = $this->files($page);
        $template = $this->resolveTemplate($page);

        $data = [
            'path' => $page->path(),
            'id' => $page->id(),
            'title' => $page->title(),
            'draft' => $page->isDraft(),
            'hidden' => $page->isHidden(),
            'sort' => $page->sort(),
            'created' => $page->created(),
            'entry' => $entry !== null ? basename($entry) : null,
            'template' => $template,
            'controller' => $this->relativePath($page->controllerFile()),
            'action' => $this->relativePath($page->actionFile()),
            'content' => $content,
            'files' => $files,
            // (object) so an empty +page.json ({}) round-trips as "{}",
            // not "[]" — PHP has no distinct empty-map type, and an empty
            // array otherwise json_encode()s as a JSON array like an empty
            // content/files list would, giving consumers a shape that
            // depends on whether any metadata happens to be present.
            'meta' => (object) $page->meta(),
        ];

        if ($json) {
            $encoded = json_encode(
                $data,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );

            if ($encoded === false) {
                return $this->fail(
                    $output,
                    true,
                    sprintf('Page data could not be encoded as JSON: %s', json_last_error_msg()),
                );
            }

            $output->writeln($encoded, OutputInterface::OUTPUT_RAW);

            return Command::SUCCESS;
        }

        $status = match (true) {
            $page->isDraft() => 'draft',
            $page->isHidden() => 'hidden (cascaded from a draft ancestor)',
            default => 'published',
        };

        $output->writeln(OutputFormatter::escape($data['path']));
        $output->writeln(sprintf('id:         %s', OutputFormatter::escape($data['id'])));
        $output->writeln(sprintf(
            'title:      %s',
            OutputFormatter::escape($data['title'] ?? '(none)'),
        ));
        $output->writeln(sprintf('status:     %s', $status));
        $output->writeln(sprintf(
            'entry:      %s',
            OutputFormatter::escape($data['entry'] ?? '(none)'),
        ));
        $output->writeln(sprintf('template:   %s', $this->templateSummary($template)));
        $output->writeln(sprintf('sort:       %d', $data['sort']));
        $output->writeln(sprintf(
            'created:    %s',
            OutputFormatter::escape($data['created'] ?? '(none)'),
        ));
        $output->writeln(sprintf(
            'controller: %s',
            OutputFormatter::escape($data['controller'] ?? '(none)'),
        ));
        $output->writeln(sprintf(
            'action:     %s',
            OutputFormatter::escape($data['action'] ?? '(none)'),
        ));

        // The freeform metadata this doc's own promise ("resolved metadata")
        // covers — id/title/created/draft/sort/template are already shown
        // above via their own accessors, but anything project-specific
        // (draft_preview, an author id, nav flags, ...) only lives here.
        // Shown raw rather than filtered to "unnamed" keys, so a future
        // field never silently goes missing because its name happens to
        // collide with one already printed above.
        $meta = $page->meta();

        if ($meta !== []) {
            $output->writeln('');
            $output->writeln('<comment>Metadata:</comment>');
            $this->writeMetaLines($output, '  ', $meta);
        }

        if ($content !== []) {
            $output->writeln('');
            $output->writeln('<comment>Content files:</comment>');

            foreach ($content as $item) {
                $output->writeln(sprintf(
                    '  %s -> %s',
                    OutputFormatter::escape($item['file']),
                    OutputFormatter::escape($item['accessor']),
                ));
            }
        }

        if ($files !== []) {
            $output->writeln('');
            $output->writeln('<comment>Files:</comment>');

            foreach ($files as $file) {
                $output->writeln(sprintf(
                    '  %s (%d bytes, %s)',
                    OutputFormatter::escape($file['name']),
                    $file['size'],
                    $file['mimeType'],
                ));
                $this->writeMetaLines($output, '    ', (array) $file['meta']);
            }
        }

        return Command::SUCCESS;
    }

    /**
     * @return array{name: string|null, file: string|null, error?: string}
     */
    private function resolveTemplate(Page $page): array
    {
        $templateFile = $page->templateFile();

        if ($templateFile !== null) {
            return ['name' => null, 'file' => $this->relativePath($templateFile)];
        }

        $resolver = new TemplateResolver(
            templatesPath: $this->app->projectPath('app') . '/templates',
            defaultTemplate: (string) $this->app->config(
                'app.rendering.default_template',
                'default',
            ),
        );

        try {
            return ['name' => $resolver->resolvePageTemplate($page->template()), 'file' => null];
        } catch (RuntimeException $e) {
            return ['name' => null, 'file' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * @param array{name: string|null, file: string|null, error?: string} $template
     */
    private function templateSummary(array $template): string
    {
        if (array_key_exists('error', $template)) {
            return '<error>' . OutputFormatter::escape($template['error']) . '</error>';
        }

        if ($template['file'] !== null) {
            return OutputFormatter::escape($template['file']) . ' (co-located)';
        }

        return OutputFormatter::escape($template['name'] ?? '(unresolved)');
    }

    /**
     * One "key: value" line per entry, value JSON-encoded since metadata is
     * freeform (strings, numbers, booleans, nested arrays). Best-effort per
     * value rather than failing the whole command: text mode is for human
     * legibility, unlike the --json branch's all-or-nothing contract (see
     * the json_encode() check above) — one unencodable value (e.g. a YAML
     * `.nan`) shouldn't blank out an otherwise-readable report.
     *
     * @param array<string, mixed> $meta
     */
    private function writeMetaLines(OutputInterface $output, string $indent, array $meta): void
    {
        foreach ($meta as $key => $value) {
            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $output->writeln(sprintf(
                '%s%s: %s',
                $indent,
                OutputFormatter::escape((string) $key),
                OutputFormatter::escape($encoded !== false ? $encoded : '(unencodable value)'),
            ));
        }
    }

    /**
     * Content files beside the page entry, paired with the `content` key
     * each parses into — the same filter PageLoader applies (supported
     * extension, not an asset's sidecar), replicated read-only here via the
     * same public statics rather than re-parsing through Page::content(),
     * which has no way to report the original filename behind each key.
     *
     * @return list<array{file: string, key: string, accessor: string}>
     */
    private function contentFiles(string $dir): array
    {
        $names = scandir($dir);

        if ($names === false) {
            return [];
        }

        sort($names);
        $files = [];

        foreach ($names as $name) {
            if (str_starts_with($name, '+') || str_starts_with($name, '.')) {
                continue;
            }

            if (!is_file($dir . '/' . $name)) {
                continue;
            }

            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            if (!FormatParser::supportsContent($extension) || Page::isAssetSidecar($dir, $name)) {
                continue;
            }

            $key = pathinfo($name, PATHINFO_FILENAME);
            $files[] = ['file' => $name, 'key' => $key, 'accessor' => $this->contentAccessor($key)];
        }

        return $files;
    }

    /**
     * The Twig expression that reaches this key on the `content` template
     * variable. Dot notation only round-trips for a key that is itself a
     * valid Twig identifier — pathinfo()'s FILENAME strips only the last
     * extension, so "main.md.json" (a supported double-extension content
     * file) keys as the literal "main.md", and "content.main.md" would
     * parse in Twig as nested attribute access (content['main']['md']),
     * not the actual key. A hyphenated key breaks more subtly still:
     * "content.my-page" tokenizes as subtraction. Bracket notation with a
     * quoted string literal is correct for every key, so it's used
     * whenever dot notation wouldn't round-trip.
     */
    private function contentAccessor(string $key): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) === 1) {
            return 'content.' . $key;
        }

        return "content['" . addcslashes($key, "\\'") . "']";
    }

    /**
     * @return list<array{name: string, size: int, mimeType: string, meta: object}>
     */
    private function files(Page $page): array
    {
        $files = [];

        foreach ($page->files() as $file) {
            $files[] = [
                'name' => $file->filename(),
                'size' => $file->size(),
                'mimeType' => $file->mimeType(),
                // (object): see the same cast on the page's own 'meta' above
                // — a file with no sidecar (or an empty one) must not
                // serialize differently from one with populated metadata.
                'meta' => (object) $file->meta(),
            ];
        }

        return $files;
    }

    private function relativePath(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        return ltrim(substr($path, strlen($this->app->rootPath())), '/');
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
