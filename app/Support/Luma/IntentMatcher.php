<?php

namespace App\Support\Luma;

use App\Models\ChatbotIntent;
use Illuminate\Support\Collection;

/**
 * Word-aware intent matching.
 *
 * A trained keyword matches only when all its words appear in the message as whole words, in order
 * ("hi" no longer matches inside "which"). Each matched keyword scores 2 points per word plus a
 * little for its length, so a phrase beats a single word, and an intent adds up its matched
 * keywords. The best intent at or above the minimum score wins; ties go to the intent with more
 * matched keywords, then the most recently edited, then the newest id.
 */
final class IntentMatcher
{
    /**
     * @param  Collection<int, ChatbotIntent>  $intents
     * @return array{intent: ChatbotIntent, score: float, matched: list<string>}|null
     */
    public static function best(string $message, Collection $intents): ?array
    {
        $words = Text::words($message);
        if ($words === []) {
            return null;
        }

        $min  = (float) config('luma.min_score', 2.0);
        $best = null;

        foreach ($intents as $intent) {
            $hit = self::score($words, (string) $intent->keywords);
            if ($hit['score'] < $min) {
                continue;
            }

            $candidate = ['intent' => $intent, 'score' => $hit['score'], 'matched' => $hit['matched']];
            if ($best === null || self::better($candidate, $best)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * @param  list<string>  $messageWords
     * @return array{score: float, matched: list<string>}
     */
    public static function score(array $messageWords, string $keywordList): array
    {
        $found = [];

        foreach (explode(',', $keywordList) as $keyword) {
            $kw = Text::words($keyword);
            if ($kw === []) {
                continue; // empty, or made only of filler words
            }

            $key = implode(' ', $kw);
            if (!isset($found[$key]) && Text::containsSequence($messageWords, $kw)) {
                $found[$key] = $kw;
            }
        }

        // A keyword that sits inside a longer matched keyword of the same intent adds nothing:
        // "payment" and "payment method" are the same hit, counted once, as the longer phrase.
        $kept = array_filter($found, function (array $kw, string $key) use ($found) {
            foreach ($found as $otherKey => $other) {
                if ($otherKey !== $key && count($other) > count($kw) && Text::containsSequence($other, $kw)) {
                    return false;
                }
            }

            return true;
        }, ARRAY_FILTER_USE_BOTH);

        $score = 0.0;
        foreach ($kept as $kw) {
            $score += self::keywordScore($kw);
        }

        return ['score' => $score, 'matched' => array_keys($kept)];
    }

    /** 2 points per word and up to 1.4 for length: "hi" 2.2, "price" 2.5, "price list" 5.0. */
    public static function keywordScore(array $words): float
    {
        return 2 * count($words) + min(strlen(implode(' ', $words)), 14) / 10;
    }

    private static function better(array $a, array $b): bool
    {
        if (abs($a['score'] - $b['score']) > 0.0001) {
            return $a['score'] > $b['score'];
        }
        if (count($a['matched']) !== count($b['matched'])) {
            return count($a['matched']) > count($b['matched']);
        }

        $ta = $a['intent']->updated_at?->getTimestamp() ?? 0;
        $tb = $b['intent']->updated_at?->getTimestamp() ?? 0;
        if ($ta !== $tb) {
            return $ta > $tb;
        }

        return $a['intent']->id > $b['intent']->id;
    }
}
