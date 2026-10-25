<?php

namespace App\Support;

/**
 * Cleans free text typed by a customer before it is validated or stored: tags are removed,
 * control characters dropped, whitespace tidied. Pair it with Rules\NoLinkOrEmail and a max rule.
 * Output is plain text; views still escape it.
 */
final class TextSanitizer
{
    /** One line (a furniture type, a wood name): no line breaks at all. */
    public static function line(?string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', self::base($text)));
    }

    /** A paragraph: line breaks kept, runs of blank lines and spaces collapsed. */
    public static function block(?string $text): string
    {
        $t = str_replace(["\r\n", "\r"], "\n", self::base($text));
        $t = preg_replace('/[^\S\n]+/u', ' ', $t);
        $t = preg_replace('/ ?\n ?/u', "\n", $t);

        return trim((string) preg_replace('/\n{3,}/', "\n\n", $t));
    }

    private static function base(?string $text): string
    {
        $t = strip_tags((string) $text);
        $t = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $t) ?? '';

        return str_replace(['<', '>'], '', $t);
    }

    public static function wordCount(string $text): int
    {
        return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY));
    }
}
