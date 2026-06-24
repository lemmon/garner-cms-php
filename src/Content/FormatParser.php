<?php

declare(strict_types=1);

namespace Garner\Content;

use JsonException;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Parses content files into named values by file extension. Structured formats
 * (JSON, YAML) decode to arrays; prose/text formats are returned as raw strings.
 */
final class FormatParser
{
    private const ENTRY_FORMATS = ['json', 'yaml', 'yml'];
    private const CONTENT_FORMATS = ['json', 'yaml', 'yml', 'md', 'markdown', 'txt'];

    public static function supportsContent(string $extension): bool
    {
        return in_array(strtolower($extension), self::CONTENT_FORMATS, true);
    }

    public static function isEntryFormat(string $extension): bool
    {
        return in_array(strtolower($extension), self::ENTRY_FORMATS, true);
    }

    public static function parse(string $path): mixed
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'json' => self::parseJson($path),
            'yaml', 'yml' => Yaml::parse(self::read($path)),
            'md', 'markdown', 'txt' => self::read($path),
            default => null,
        };
    }

    private static function read(string $path): string
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(sprintf('Unable to read content file "%s"', $path));
        }

        return $contents;
    }

    private static function parseJson(string $path): mixed
    {
        try {
            return json_decode(self::read($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                sprintf('Invalid JSON in "%s": %s', $path, $exception->getMessage()),
                0,
                $exception,
            );
        }
    }
}
