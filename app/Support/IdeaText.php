<?php

namespace App\Support;

use App\Rules\NoLinkOrEmail;

/**
 * The text rules the Customize page and its admin screens share for the short phrases (quick ideas, extra furniture
 * types, answers): tags out, spaces tidied, no links or emails, a length cap. Built on TextSanitizer and NoLinkOrEmail.
 */
final class IdeaText
{
    public const IDEA_MAX = 80;

    public static function line(?string $text): string
    {
        return TextSanitizer::line($text);
    }

    /** @return list<mixed> rules for one short single-line phrase */
    public static function lineRules(int $max, int $min = 2): array
    {
        return ['required', 'string', 'min:' . $min, 'max:' . $max, new NoLinkOrEmail()];
    }
}
