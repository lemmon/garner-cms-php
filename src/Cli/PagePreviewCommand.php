<?php

declare(strict_types=1);

namespace Garner\Cli;

use Garner\Content\EntryFile;
use Garner\Content\FormatParser;
use Garner\Content\InvalidEntryException;
use Garner\Content\PageMeta;
use Garner\Content\PublicSite;
use Garner\Content\RoutePath;
use Garner\Core\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Manage a page's `draft_preview` link secret (see PageMeta, PublicSite). With
 * no `--set`/`--generate`/`--clear`/`--open`, reports the current state
 * instead of changing anything. `--open` is a separate, lighter-weight path:
 * a one-time token that never touches `+page.json`, meant for a quick local
 * look rather than a link handed to someone else (see PublicSite's ephemeral
 * preview cache).
 */
#[AsCommand(name: 'page:preview', description: 'Set, show, or clear a page\'s draft preview link')]
final class PagePreviewCommand extends Command
{
    /**
     * @param \Closure(string): bool|null $browserOpener Overrides how --open
     *        launches a browser. Tests inject a fake so running the suite
     *        never actually spawns one; production always gets null, which
     *        resolves to the real `open`/`xdg-open`/`start` subprocess call.
     */
    public function __construct(
        private readonly Application $app,
        private readonly ?\Closure $browserOpener = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('route', InputArgument::REQUIRED, 'Route path, e.g. blog/hello');
        $this->addOption(
            'set',
            null,
            InputOption::VALUE_REQUIRED,
            'Set an explicit preview phrase',
        );
        $this->addOption(
            'generate',
            null,
            InputOption::VALUE_NONE,
            'Generate a random preview token',
        );
        $this->addOption('clear', null, InputOption::VALUE_NONE, 'Remove the preview link');
        $this->addOption(
            'open',
            null,
            InputOption::VALUE_NONE,
            'Open a one-time local preview link (does not touch +page.json)',
        );
        $this->addOption(
            'base-url',
            null,
            InputOption::VALUE_REQUIRED,
            'Site base URL for --open, e.g. http://localhost:8040 (defaults to app.url)',
        );
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

        // On a case-insensitive filesystem (macOS, Windows — exactly where
        // --open's browser launch matters most), a typed route like "WIP"
        // locates this file fine, but the indexed route path PublicSite
        // actually compares against is case-sensitive and reflects the real
        // on-disk directory name. is_file()/is_dir()/realpath() all answer
        // case-insensitively there too rather than correcting case, so a
        // real directory listing is the only reliable source.
        $canonicalSegments = $this->realSegments($this->app->projectPath('routes'), $segments);

        if ($canonicalSegments === null) {
            return $this->fail($output, $json, sprintf('No page exists at "/%s".', $route));
        }

        $route = implode('/', $canonicalSegments);

        $meta = FormatParser::parse($entry);

        if (!is_array($meta)) {
            return $this->fail(
                $output,
                $json,
                sprintf('Entry "%s" must decode to an object.', $entry),
            );
        }

        $set = $input->getOption('set');
        $generate = $input->getOption('generate') === true;
        $clear = $input->getOption('clear') === true;
        $open = $input->getOption('open') === true;

        if (count(array_filter([$set !== null, $generate, $clear, $open])) > 1) {
            return $this->fail(
                $output,
                $json,
                'Use only one of --set, --generate, --clear, --open.',
            );
        }

        if ($open) {
            // PublicSite resolves an already-visible page before it ever looks
            // at ?preview=, so a token issued for a public page is never
            // consumed — the printed "one-time" link would just be the page's
            // ordinary, permanent URL with an inert query string attached.
            $target = $this->app->pages()->find(RoutePath::normalize('/' . $route), drafts: true);

            if ($target !== null && !$target->isHidden()) {
                return $this->fail(
                    $output,
                    $json,
                    sprintf(
                        '"/%s" is already public; --open only applies to a hidden (draft) page.',
                        $route,
                    ),
                );
            }

            return $this->openPreview($output, $json, $route, $input->getOption('base-url'));
        }

        // Only a mutating mode needs the JSON-only restriction: --open and the
        // status report below both work fine reading a YAML entry, since
        // FormatParser::parse() already handles it — only write() doesn't.
        $mutating = $clear || $generate || is_string($set);

        if ($mutating && !str_ends_with($entry, '.json')) {
            return $this->fail(
                $output,
                $json,
                sprintf(
                    '"/%s" uses a %s entry; page:preview only edits +page.json.',
                    $route,
                    basename($entry),
                ),
            );
        }

        if ($clear) {
            if (!array_key_exists('draft_preview', $meta)) {
                return $this->report($output, $json, $route, null, 'already off');
            }

            unset($meta['draft_preview']);

            if (!$this->write($entry, $meta)) {
                return $this->fail($output, $json, sprintf('Unable to write "%s".', $entry));
            }

            return $this->report($output, $json, $route, null, 'removed');
        }

        if (!$generate && !is_string($set)) {
            $current = $meta['draft_preview'] ?? null;
            $current = is_string($current) ? $current : null;

            return $this->report(
                $output,
                $json,
                $route,
                $current,
                $current !== null ? 'on' : 'off',
            );
        }

        if (is_string($set) && trim($set) === '') {
            return $this->fail($output, $json, 'The preview phrase cannot be empty.');
        }

        $value = $generate ? bin2hex(random_bytes(12)) : $set;
        $meta['draft_preview'] = $value;

        try {
            PageMeta::assertValid($meta, $entry);
        } catch (InvalidEntryException $e) {
            return $this->fail($output, $json, $e->getMessage());
        }

        if (!$this->write($entry, $meta)) {
            return $this->fail($output, $json, sprintf('Unable to write "%s".', $entry));
        }

        return $this->report($output, $json, $route, $value, 'set');
    }

    /**
     * Issues a one-time preview token via the disposable application cache
     * (never written to +page.json) and best-effort opens it in the default
     * browser — always printing the link too, since browser-launch support
     * varies by OS and environment.
     */
    private function openPreview(
        OutputInterface $output,
        bool $json,
        string $route,
        mixed $baseUrlOption,
    ): int {
        $baseUrl = $this->resolveBaseUrl($baseUrlOption);

        if ($baseUrl === null) {
            return $this->fail(
                $output,
                $json,
                'Unable to determine the site URL. Pass'
                . ' --base-url (e.g. http://localhost:8040) or set APP_URL.',
            );
        }

        $canonical = RoutePath::normalize('/' . $route);
        $token = bin2hex(random_bytes(12));
        $ttl = $this->previewOpenTtl();

        $this->app->cache()->set(
            PublicSite::EPHEMERAL_PREVIEW_CACHE_PREFIX . $canonical,
            $token,
            $ttl,
        );

        $url = $baseUrl . $canonical . '?preview=' . $token;
        $opened = $this->openInBrowser($url);

        if ($json) {
            $output->writeln((string) json_encode([
                'route' => $canonical,
                'url' => $url,
                'ttlSeconds' => $ttl,
                'opened' => $opened,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $output->writeln(sprintf('One-time preview link (expires in %d minutes, works once):', intdiv(
            $ttl,
            60,
        )));
        $output->writeln($url);

        if (!$opened) {
            $output->writeln('<comment>Could not open a browser automatically.</comment>');
        }

        return Command::SUCCESS;
    }

    private function resolveBaseUrl(mixed $option): ?string
    {
        if (is_string($option) && trim($option) !== '') {
            return rtrim(trim($option), '/');
        }

        $configured = $this->app->config('app.url');

        return is_string($configured) && trim($configured) !== ''
            ? rtrim(trim($configured), '/')
            : null;
    }

    private function previewOpenTtl(): int
    {
        $configured = $this->app->config('app.preview.open_ttl');

        return is_int($configured) && $configured > 0 ? $configured : 1800;
    }

    /**
     * Best-effort: opens $url in the OS default browser via an argv-array
     * subprocess call (no shell interpolation of $url). Returns false rather
     * than throwing when the OS isn't recognized or launching fails — the
     * caller always prints the link too, so this is convenience, not the
     * only way to reach it.
     */
    private function openInBrowser(string $url): bool
    {
        if ($this->browserOpener !== null) {
            return ($this->browserOpener)($url);
        }

        // A disabled proc_open() isn't merely undefined-and-warns — PHP raises
        // an uncaught Error for it, which would break the "always print the
        // link, never crash" contract this method promises. function_exists()
        // avoids the call entirely; the catch is defense in depth for any
        // other unexpected failure to launch.
        if (!function_exists('proc_open')) {
            return false;
        }

        $command = match (PHP_OS_FAMILY) {
            'Darwin' => ['open', $url],
            'Windows' => ['cmd', '/c', 'start', '', $url],
            default => ['xdg-open', $url],
        };

        set_error_handler(static fn(): bool => true);

        try {
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        } catch (\Throwable) {
            return false;
        } finally {
            restore_error_handler();
        }

        if (!is_resource($process)) {
            return false;
        }

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        return proc_close($process) === 0;
    }

    /**
     * Writes via a sibling temp file plus rename rather than truncating the
     * entry in place: an in-place file_put_contents() that short-writes (disk
     * full, etc.) destroys the previously-good content on the way to
     * reporting failure, and a concurrent reader (the live site, if it's
     * running) could observe a half-written file mid-write. The temp file
     * isolates that risk entirely — a failed write never touches $entry — and
     * rename() is atomic, so a concurrent reader only ever sees the fully-old
     * or fully-new content, never a torn one.
     *
     * @param array<string, mixed> $meta
     */
    private function write(string $entry, array $meta): bool
    {
        $encoded = json_encode(
            $meta,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
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

        // A short write (disk full mid-write, etc.) never reaches $entry —
        // only the disposable temp file is affected. Best-effort cleanup: a
        // leftover .tmp-* file is harmless clutter, not worth failing over.
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
     * spelling — an exact match wins where one exists (so two genuinely
     * distinct directories differing only by case, legal on a case-sensitive
     * filesystem, still resolve to whichever the caller actually typed);
     * otherwise the first case-insensitive match, which is what a
     * case-insensitive filesystem actually resolved when locating the entry
     * file. Null if a segment genuinely isn't there (defensive —
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
     * An empty list means the home route ("", "/", "///", ...) — unlike
     * CreatePageCommand's version of this helper, home is meaningful here:
     * an existing home page can be a draft with its own preview state, same
     * as any other page.
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

    private function report(
        OutputInterface $output,
        bool $json,
        string $route,
        ?string $value,
        string $state,
    ): int {
        $path = $route === '' ? '/' : '/' . $route;

        if ($json) {
            $output->writeln((string) json_encode([
                'route' => $path,
                'state' => $state,
                'value' => $value,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $output->writeln(
            $value !== null
                ? sprintf('%s preview (%s): %s', $path, $state, $value)
                : sprintf('%s preview: %s', $path, $state),
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

        $output->writeln('<error>' . $message . '</error>');

        return Command::FAILURE;
    }
}
