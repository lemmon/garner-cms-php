<?php

declare(strict_types=1);

namespace Garner\Cli;

use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Key and JSON-value argument handling shared by the store:* commands, kept
 * in one place so a fix to any of the three — the "Provide a key." message,
 * the JSON parse error, or key escaping — doesn't have to be copied across
 * every command that reads a "key" (and, for add/set, "value") argument.
 */
trait ParsesStoreArguments
{
    use WritesCliError;

    /**
     * The "key" argument, or null (after writing the error) when it is
     * missing or empty.
     */
    private function requireStoreKey(InputInterface $input, OutputInterface $output): ?string
    {
        $key = $input->getArgument('key');

        if (!is_string($key) || $key === '') {
            $output->writeln('<error>Provide a key.</error>');

            return null;
        }

        return $key;
    }

    /**
     * The "prefix" argument, defaulting to "" when absent or non-string.
     */
    private function storePrefix(InputInterface $input): string
    {
        $prefix = $input->getArgument('prefix');

        return is_string($prefix) ? $prefix : '';
    }

    /**
     * The "value" argument, decoded as JSON.
     *
     * @throws InvalidArgumentException when the raw argument isn't valid
     *     JSON. Deliberately the same exception type Store::encode() throws
     *     for a value that parses but can't be stored (e.g. a numeric
     *     literal like "1e400", which decodes to INF and passes JSON
     *     parsing but isn't JSON-encodable) — callers catch both with one
     *     try/catch instead of getting an uncaught exception from the
     *     store write.
     */
    private function parseStoreJsonValue(InputInterface $input): mixed
    {
        $raw = $input->getArgument('value');
        $raw = is_string($raw) ? $raw : '';
        $value = json_decode($raw, true);

        // json_decode() returning null is ambiguous ("null" decodes to null
        // too), so invalidity is judged by the parser's error state.
        if ($value === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException(sprintf(
                'The value is not valid JSON (%s). Quote strings: \'"hello"\'.',
                json_last_error_msg(),
            ));
        }

        return $value;
    }

    /**
     * The store:set / store:add command shape: resolve "key" and "value",
     * then hand both to $write, which performs the actual store operation
     * and returns the command's exit code. Any InvalidArgumentException —
     * missing key, invalid JSON, or a value Store::encode() rejects while
     * $write runs — is caught and reported the same way, so $write need
     * not catch it itself.
     */
    private function withStoreKeyAndValue(
        InputInterface $input,
        OutputInterface $output,
        callable $write,
    ): int {
        $key = $this->requireStoreKey($input, $output);

        if ($key === null) {
            return Command::FAILURE;
        }

        try {
            $value = $this->parseStoreJsonValue($input);

            return $write($key, $value);
        } catch (InvalidArgumentException $exception) {
            $this->writeStoreArgumentError($output, $exception);

            return Command::FAILURE;
        }
    }

    /**
     * Writes $exception's message as an error line. Escaped, because
     * Store::encode()'s message embeds user input (the key) that must
     * display as-is rather than being interpreted as console markup;
     * parseStoreJsonValue()'s message never does, but both share this
     * exception type and are escaped uniformly here regardless of source.
     */
    private function writeStoreArgumentError(
        OutputInterface $output,
        InvalidArgumentException $exception,
    ): void {
        $this->writeCliError($output, $exception->getMessage());
    }

    /**
     * A key, escaped for safe interpolation into a formatted console
     * message: a key is an arbitrary stored string, and one containing
     * console-markup-shaped text must display as-is rather than being
     * interpreted (or breaking the formatter on malformed nesting).
     */
    private function escapeStoreKey(string $key): string
    {
        return OutputFormatter::escape($key);
    }
}
