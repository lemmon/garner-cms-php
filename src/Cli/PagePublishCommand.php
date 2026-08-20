<?php

declare(strict_types=1);

namespace Garner\Cli;

use Garner\Content\EntryFile;
use Garner\Content\InvalidEntryException;
use Garner\Content\PageMeta;
use Garner\Core\Application;
use JsonException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Clears a page's own `draft` flag. The key is removed rather than written
 * as `false` — absence is what PageMeta::isDraft() and page:create's
 * scaffolding already treat as "published" (see CreatePageCommand, which
 * only ever writes `draft` when true). A page can still be hidden afterwards
 * if a draft ancestor cascades onto it (see Page::isHidden()); this command
 * only ever touches the page's own flag. The inverse of PageDraftCommand.
 */
#[AsCommand(name: 'page:publish', description: "Clear a page's own draft flag")]
final class PagePublishCommand extends Command
{
    use WritesCliError;

    public function __construct(
        private readonly Application $app,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('route', InputArgument::REQUIRED, 'Route path, e.g. blog/hello');
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
        $dir = $route === ''
            ? $this->app->projectPath('routes')
            : $this->app->projectPath('routes') . '/' . $route;
        $entry = EntryFile::find($dir);

        if ($entry === null) {
            return $this->fail($output, $json, sprintf('No page exists at "/%s".', $route));
        }

        // On a case-insensitive filesystem, a typed route like "Blog" locates
        // this file fine, but the route reported back should reflect the real
        // on-disk directory spelling — see PagePreviewCommand::realSegments()
        // for the same reasoning.
        $canonicalSegments = $this->realSegments($this->app->projectPath('routes'), $segments);

        if ($canonicalSegments === null) {
            return $this->fail($output, $json, sprintf('No page exists at "/%s".', $route));
        }

        $route = implode('/', $canonicalSegments);

        if (!str_ends_with($entry, '.json')) {
            return $this->fail(
                $output,
                $json,
                sprintf(
                    '"/%s" uses a %s entry; page:publish only edits +page.json.',
                    $route,
                    basename($entry),
                ),
            );
        }

        $decoded = $this->readEntry($entry);

        if (!is_object($decoded)) {
            return $this->fail(
                $output,
                $json,
                sprintf('Entry "%s" must decode to an object.', $entry),
            );
        }

        $meta = (array) $decoded;

        if (!PageMeta::isDraft($meta)) {
            return $this->report($output, $json, $route, false);
        }

        unset($meta['draft']);

        try {
            PageMeta::assertValid($meta, $entry);
        } catch (InvalidEntryException $e) {
            return $this->fail($output, $json, $e->getMessage());
        }

        if (!$this->write($entry, $meta)) {
            return $this->fail($output, $json, sprintf('Unable to write "%s".', $entry));
        }

        // The write above only changes this one entry file's mtime; a fresh
        // scan-mode read fingerprints every page's dir:mtime together (see
        // ContentIndex::fingerprint()) and only persists the rebuilt rows
        // when that hash changed from what's stored. A second draft/publish
        // on the same page inside the same wall-clock second can land on an
        // identical fingerprint to the first write, so the index would keep
        // serving the pre-write (wrong) hidden state indefinitely — not just
        // until the next read. Rebuilding here, synchronously, is the only
        // way to guarantee this specific edit is reflected regardless of
        // timing, in both "scan" and "locked" mode.
        $this->app->contentIndex()->rebuild();

        return $this->report($output, $json, $route, true);
    }

    /**
     * Decodes the entry in object mode — not FormatParser's associative
     * array — so a `{}` and a `[]` nested anywhere in the metadata stay
     * distinguishable through the read-mutate-write round trip above. An
     * associative decode collapses both to the same empty PHP array, and
     * re-encoding then can't tell which one it started as (see write()).
     * JSON_BIGINT_AS_STRING keeps an integer too large for PHP's native int
     * exact, as a numeric string, instead of silently rounding it through a
     * lossy float cast.
     */
    private function readEntry(string $entry): mixed
    {
        $contents = file_get_contents($entry);

        if ($contents === false) {
            throw new RuntimeException(sprintf('Unable to read content file "%s"', $entry));
        }

        try {
            return json_decode($contents, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                sprintf('Invalid JSON in "%s": %s', $entry, $exception->getMessage()),
                0,
                $exception,
            );
        }
    }

    /**
     * Writes via a sibling temp file plus rename rather than truncating the
     * entry in place — see PagePreviewCommand::write() for the full
     * rationale (short-write and concurrent-reader safety).
     * JSON_PRESERVE_ZERO_FRACTION keeps a whole-number float (e.g. `1.0`) as
     * a float in the output — without it, json_encode() writes `1`, quietly
     * changing an untouched freeform field's JSON type. The `(object)` cast
     * keeps the top level an entry object even once every key has been
     * removed from it — an empty PHP array and an empty PHP object both
     * decode from (and re-encode to) `{}` everywhere else in this file, but
     * a *bare* empty array encodes as `[]`, and $meta is a bare array once
     * unset($meta['draft']) above removes a page's only key.
     *
     * @param array<string, mixed> $meta
     */
    private function write(string $entry, array $meta): bool
    {
        $encoded = json_encode(
            (object) $meta,
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION,
        );

        if (!is_string($encoded)) {
            return false;
        }

        $content = $encoded . "\n";
        $temp = $entry . '.tmp-' . bin2hex(random_bytes(6));

        set_error_handler(static fn(): bool => true);

        try {
            $written = file_put_contents($temp, $content);
        } finally {
            restore_error_handler();
        }

        if ($written !== strlen($content)) {
            set_error_handler(static fn(): bool => true);

            try {
                unlink($temp);
            } finally {
                restore_error_handler();
            }

            return false;
        }

        set_error_handler(static fn(): bool => true);

        try {
            return rename($temp, $entry);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Walks $segments from $root, replacing each with its real on-disk
     * spelling. Null if a segment genuinely isn't there (defensive —
     * EntryFile::find() already confirmed the full path resolves before this
     * runs). Empty $segments (home) returns [] immediately.
     *
     * @param list<string> $segments
     *
     * @return list<string>|null
     */
    private function realSegments(string $root, array $segments): ?array
    {
        $dir = $root;
        $real = [];

        foreach ($segments as $segment) {
            $entries = scandir($dir);

            if ($entries === false) {
                return null;
            }

            $match = null;

            foreach ($entries as $entry) {
                if ($entry !== $segment) {
                    continue;
                }

                $match = $entry;

                break;
            }

            if ($match === null) {
                foreach ($entries as $entry) {
                    if (strcasecmp($entry, $segment) !== 0) {
                        continue;
                    }

                    $match = $entry;

                    break;
                }
            }

            if ($match === null) {
                return null;
            }

            $real[] = $match;
            $dir .= '/' . $match;
        }

        return $real;
    }

    /**
     * An empty list means the home route ("", "/", "///", ...) — the home
     * page can be published/unpublished with its own state, same as any
     * other page.
     *
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

    private function report(OutputInterface $output, bool $json, string $route, bool $changed): int
    {
        $path = $route === '' ? '/' : '/' . $route;

        if ($json) {
            $output->writeln((string) json_encode([
                'route' => $path,
                'draft' => false,
                'changed' => $changed,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $output->writeln(
            $changed
                ? sprintf('%s is now published.', $path)
                : sprintf('%s is already published.', $path),
        );

        return Command::SUCCESS;
    }

    private function fail(OutputInterface $output, bool $json, string $message): int
    {
        if ($json) {
            $output->writeln((string) json_encode([
                'error' => $message,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::FAILURE;
        }

        $this->writeCliError($output, $message);

        return Command::FAILURE;
    }
}
