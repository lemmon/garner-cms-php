<?php

declare(strict_types=1);

namespace Garner\Support;

use InvalidArgumentException;
use Stringable;

/**
 * Text helpers behind Garner's Twig filters (`markdown`, `first_paragraph`,
 * `excerpt`). Lengths count characters (Unicode code points), not bytes.
 */
final class Text
{
    /**
     * Up to %d characters ending before a space, or before punctuation
     * followed by a space: where a cut keeps whole words. Punctuation inside
     * a token (`1.5`, `1,250`, `2.10.7`, `https:`) is not a boundary.
     */
    private const string BOUNDARY = '/^(.{1,%d})(?:\s|[,;:.!?\x{2013}\x{2014}](?=\s))/su';

    /**
     * A template value as text: strings and Stringables (such as Twig's
     * Markup) as they are, numbers and booleans spelled out, anything else
     * (null, arrays, plain objects) empty.
     */
    public static function from(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            is_bool($value) => $value ? 'true' : 'false',
            $value instanceof Stringable => (string) $value,
            default => '',
        };
    }

    /**
     * Plain text of at most $length characters (the ellipsis included),
     * cut after the last word that fits; the space or punctuation after it
     * is dropped. Text that fits is returned whole, without an ellipsis.
     *
     * With $html, the text is first reduced from HTML (see plain()); without it, `<` and `&`
     * are ordinary characters. Whitespace is collapsed either way.
     *
     * A boundary that would use less than half the room (one long URL after
     * the first word), or leave nothing, gives way to a cut mid-word. An
     * ellipsis that would leave no room for text is dropped.
     */
    public static function excerpt(
        string $text,
        int $length = 160,
        string $ellipsis = '…',
        bool $html = false,
    ): string {
        if ($length < 1) {
            throw new InvalidArgumentException(sprintf(
                'excerpt length must be at least 1, got %d',
                $length,
            ));
        }

        if (!mb_check_encoding($text, 'UTF-8') || !mb_check_encoding($ellipsis, 'UTF-8')) {
            throw new InvalidArgumentException('excerpt text and ellipsis must be valid UTF-8');
        }

        $plain = $html ? self::plain($text) : self::collapse($text);

        if (mb_strlen($plain, 'UTF-8') <= $length) {
            return $plain;
        }

        if (mb_strlen($ellipsis, 'UTF-8') >= $length) {
            $ellipsis = '';
        }

        $budget = $length - mb_strlen($ellipsis, 'UTF-8');
        $hard = self::trimEnd(mb_substr($plain, 0, $budget, 'UTF-8'));
        // Two characters past the budget, so a word followed by a space, or
        // by punctuation and a space, still counts as fitting. Where the
        // window reaches the end of the text, that end counts as a space.
        $window = mb_substr($plain, 0, $budget + 2, 'UTF-8');

        if (mb_strlen($window, 'UTF-8') === mb_strlen($plain, 'UTF-8')) {
            $window .= ' ';
        }

        // PCRE caps a repeat count at 65535; a longer budget just looks for
        // its boundary within the first 65535 characters.
        $pattern = sprintf(self::BOUNDARY, min($budget, 65_535));
        $cut = preg_match($pattern, $window, $match) === 1 ? self::trimEnd($match[1]) : '';

        if (mb_strlen($cut, 'UTF-8') < intdiv($budget, 2) || $cut === '') {
            $cut = $hard !== '' ? $hard : mb_substr($plain, 0, $budget, 'UTF-8');
        }

        return $cut . $ellipsis;
    }

    /**
     * The text of an HTML fragment: tags stripped, entities decoded,
     * whitespace (non-breaking spaces included) collapsed and trimmed. Exact
     * for CommonMark's output, which puts a line break between blocks;
     * other HTML is taken as written, so `<p>a</p><p>b</p>` reads as `ab`
     * and a `<style>` element's contents remain.
     */
    public static function plain(string $html): string
    {
        return self::collapse(html_entity_decode(
            strip_tags($html),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8',
        ));
    }

    /**
     * Runs of whitespace, Unicode spaces included, as one space; trimmed.
     * Invisible characters that editors leave behind (soft hyphen,
     * zero-width space, word joiner, byte order mark) are dropped, so they
     * neither count as text nor take up the length.
     */
    private static function collapse(string $text): string
    {
        $text = (string) preg_replace('/[\x{00AD}\x{200B}\x{2060}\x{FEFF}]+/u', '', $text);

        return trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $text));
    }

    /**
     * Drops a dangling space, punctuation mark, dash or ellipsis, so it
     * doesn't sit before the ellipsis.
     */
    private static function trimEnd(string $text): string
    {
        return (string) preg_replace('/[\s,;:.!?\x{2013}\x{2014}\x{2026}-]+$/u', '', $text);
    }
}
