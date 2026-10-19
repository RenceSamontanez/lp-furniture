<?php

namespace App\Support\Luma;

/**
 * The small judgements Luma makes about HOW something was said rather than WHAT it was about: a bare "yes" or
 * "sige", "how" / "paano po" / "ano po?", "when", thanks and goodbye, and whether the customer sounds stressed or angry.
 * They work on the plain tokens of the message (Text::plain), because words like "po" and "ba" are filler to the
 * keyword matcher but are exactly what makes "opo" or "ano po?" a complete answer.
 */
final class Context
{
    private const AFFIRM  = ['yes', 'yeah', 'yep', 'yup', 'ok', 'okay', 'sure', 'sige', 'oo', 'opo', 'go', 'please', 'pls', 'proceed', 'tara', 'k', 'kk', 'oks', 'sige', 'do', 'it', 'that', 'thats', 'right', 'tama'];
    private const HOW     = ['how', 'paano', 'pano', 'ano', 'what', 'bakit', 'why'];
    private const WHEN    = ['when', 'kailan', 'kelan'];
    private const FILLER  = ['po', 'opo', 'ba', 'naman', 'lang', 'na', 'so', 'then', 'again', 'sir', 'maam', 'mam', 'is', 'it', 'about', 'that', 'the'];
    private const THANKS  = ['thanks', 'thank', 'thankyou', 'salamat', 'ty', 'tnx', 'thx', 'maraming'];
    private const BYE     = ['bye', 'goodbye', 'paalam', 'ingat', 'see', 'you'];
    private const ANGRY   = ['galit', 'inis', 'nakakainis', 'badtrip', 'bwisit', 'nakakabwisit', 'nakakagalit', 'nakakaasar', 'scam', 'scammer', 'sinungaling', 'worst', 'ridiculous', 'unacceptable', 'frustrated', 'angry', 'annoyed', 'furious', 'disappointed', 'upset', 'useless', 'walang kwenta', 'grabe', 'ayoko', 'nakakadismaya', 'stressed', 'stress', 'kainis'];

    /** @return list<string> */
    public static function tokens(string $message): array
    {
        return array_values(array_filter(explode(' ', Text::plain($message))));
    }

    private static function onlyFrom(array $tokens, array $allowed): bool
    {
        return $tokens !== [] && array_diff($tokens, $allowed, self::FILLER) === [];
    }

    public static function isAffirmation(string $message): bool
    {
        $t = self::tokens($message);

        return $t !== [] && count($t) <= 4 && self::onlyFrom($t, self::AFFIRM) && array_intersect($t, self::AFFIRM) !== [];
    }

    /** "how", "paano po", "ano po?", "what do you mean": asking for the how or the what of the last topic. */
    public static function isHow(string $message): bool
    {
        $t = self::tokens($message);

        return $t !== [] && count($t) <= 4 && array_intersect($t, self::HOW) !== [] && self::onlyFrom($t, array_merge(self::HOW, ['mean', 'you', 'do', 'does', 'that', 'work', 'works', 'gumagana', 'ibig', 'sabihin', 'nyan', 'yan']));
    }

    public static function isWhen(string $message): bool
    {
        $t = self::tokens($message);

        return $t !== [] && count($t) <= 4 && array_intersect($t, self::WHEN) !== [] && self::onlyFrom($t, array_merge(self::WHEN, ['will', 'it', 'happen', 'ito', 'yan', 'mangyayari', 'darating']));
    }

    /** "and if I already paid?", "paano kung nagbayad na ako": a paid-already follow-up. */
    public static function isPaidFollowUp(string $message): bool
    {
        $w = Text::words($message);

        return count(self::tokens($message)) <= 9
            && array_intersect($w, ['paid']) !== []
            && array_intersect(self::tokens($message), ['if', 'kung', 'what', 'paano', 'pano', 'and', 'then', 'kapag', 'pag', 'how']) !== [];
    }

    public static function isThanks(string $message): bool
    {
        $t = self::tokens($message);

        return $t !== [] && count($t) <= 5 && array_intersect($t, self::THANKS) !== [] && self::onlyFrom($t, array_merge(self::THANKS, ['you', 'so', 'much', 'very', 'a', 'lot', 'po', 'ang', 'inyo', 'ninyo', 'sa', 'tulong']));
    }

    public static function isGoodbye(string $message): bool
    {
        $t = self::tokens($message);

        return $t !== [] && count($t) <= 4 && array_intersect($t, self::BYE) !== [] && self::onlyFrom($t, array_merge(self::BYE, ['now', 'na', 'po', 'sige', 'sa', 'susunod', 'ulit', 'again', 'soon', 'take', 'care']));
    }

    /** Raised voice (!! or SHOUTING), or an angry word. Used only to put one empathy line before the facts. */
    public static function isStressed(string $message): bool
    {
        if (substr_count($message, '!') >= 2) {
            return true;
        }
        if (preg_match_all('/\b[A-Z]{4,}\b/', $message) >= 2) {
            return true;
        }
        $plain = ' ' . Text::plain($message) . ' ';
        foreach (self::ANGRY as $word) {
            if (str_contains($plain, ' ' . Text::plain($word) . ' ')) {
                return true;
            }
        }

        return false;
    }

    /** One empathy line (understanding, or an apology when the answer itself has none). Never stacked: see Luma::finish. */
    public static function empathyLine(string $lang, bool $apologise): string
    {
        $tl = $lang === Language::TL;

        return $apologise
            ? ($tl ? 'Pasensya na po sa abala.' : "I'm sorry you're dealing with this.")
            : ($tl ? 'Naiintindihan ko po, at gusto ko kayong tulungan.' : 'I understand, and I want to help.');
    }

    public static function startsWithApology(string $text): bool
    {
        return (bool) preg_match("/^\s*(I'm sorry|I am sorry|Sorry|Pasensya na|Paumanhin)/iu", $text);
    }
}
