<?php

namespace App\Support\Luma;

use Illuminate\Support\Str;

/**
 * Turns a message or a trained keyword into a list of plain words that can be compared
 * whole-word: lower case, no accents, no punctuation, synonyms and typos from
 * config/luma_synonyms.php applied, plurals folded, filler words dropped.
 * Messages and keywords go through the exact same steps, so they always line up.
 */
final class Text
{
    /** @var array{phrases: array<string,string>, words: array<string,string>}|null */
    private static ?array $synonyms = null;

    /** @var array<string, list<string>> */
    private static array $memo = [];

    /** The plain lower-case, accent-free text with every punctuation mark turned into a space. */
    public static function plain(string $text): string
    {
        $text = Str::lower(Str::ascii($text));
        $text = preg_replace('/[^a-z0-9]+/', ' ', $text) ?? '';

        return trim(preg_replace('/\s+/', ' ', $text) ?? '');
    }

    /**
     * @return list<string>
     */
    public static function words(string $text): array
    {
        // Keywords are re-read for every message, so the result is remembered per text (cleared by reset()).
        if (isset(self::$memo[$text])) {
            return self::$memo[$text];
        }
        if (count(self::$memo) > 20000) {
            self::$memo = [];
        }

        return self::$memo[$text] = self::compute($text);
    }

    /** @return list<string> */
    private static function compute(string $text): array
    {
        $plain = ' ' . self::plain($text) . ' ';
        $map   = self::synonyms();

        foreach ($map['phrases'] as $from => $to) {
            $from = self::plain($from);
            if ($from !== '') {
                $plain = str_replace(" {$from} ", " {$to} ", $plain);
            }
        }

        $stop = array_flip(config('luma.stop_words', []));
        $out  = [];

        foreach (explode(' ', trim($plain)) as $token) {
            if ($token === '') {
                continue;
            }
            $mapped = $map['words'][$token] ?? $map['words'][self::fold($token)] ?? $token;
            foreach (explode(' ', $mapped) as $piece) {
                $piece = self::fold($piece);
                if ($piece !== '' && !isset($stop[$piece])) {
                    $out[] = $piece;
                }
            }
        }

        return $out;
    }

    /** Folds a plain plural: doors → door. Words ending in ss, us or is are left alone. */
    public static function fold(string $word): string
    {
        if (strlen($word) > 3 && str_ends_with($word, 's') && !preg_match('/(ss|us|is)$/', $word)) {
            return substr($word, 0, -1);
        }

        return $word;
    }

    /** True when $needle (a list of words) appears inside $haystack as whole words, in order. */
    public static function containsSequence(array $haystack, array $needle): bool
    {
        $n = count($needle);
        if ($n === 0 || $n > count($haystack)) {
            return false;
        }

        for ($i = 0, $last = count($haystack) - $n; $i <= $last; $i++) {
            if (array_slice($haystack, $i, $n) === $needle) {
                return true;
            }
        }

        return false;
    }

    /** @return array{phrases: array<string,string>, words: array<string,string>} */
    private static function synonyms(): array
    {
        if (self::$synonyms === null) {
            $raw   = config('luma_synonyms', []);
            $words = [];
            foreach ($raw['words'] ?? [] as $from => $to) {
                $words[self::plain($from)] = self::plain($to);
            }
            $phrases = [];
            foreach ($raw['phrases'] ?? [] as $from => $to) {
                $phrases[self::plain($from)] = self::plain($to);
            }
            // longest phrase first, so "how many days" is tried before "how"
            uksort($phrases, fn ($a, $b) => strlen($b) <=> strlen($a));

            self::$synonyms = ['phrases' => $phrases, 'words' => $words];
        }

        return self::$synonyms;
    }

    /** Forget the loaded synonym list (tests that change it). */
    public static function reset(): void
    {
        self::$synonyms = null;
        self::$memo     = [];
    }
}
