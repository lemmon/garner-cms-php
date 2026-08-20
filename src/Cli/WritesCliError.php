<?php

declare(strict_types=1);

namespace Garner\Cli;

use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Writes a message as an escaped <error> line, shared by CLI commands that
 * report a single-line error on non-JSON output: the message may embed user
 * input (e.g. a key or path) that must display as-is rather than being
 * interpreted as console markup.
 */
trait WritesCliError
{
    private function writeCliError(OutputInterface $output, string $message): void
    {
        $output->writeln('<error>' . OutputFormatter::escape($message) . '</error>');
    }
}
