<?php

namespace App\Support\Luma;

use App\Models\LumaUnansweredQuestion;
use App\Rules\NoInappropriateTerms;
use Blaspsoft\Blasp\Rules\Profanity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

/**
 * Keeps the questions Luma could not answer so the team can teach her, without keeping junk or personal details.
 *
 * Skipped (nothing stored): a link or domain, profanity or adult terms (English via Blasp, Tagalog and the shop's own
 * list), fewer than 3 or more than 200 characters, gibberish or repeated characters, and a visitor who has already saved
 * too many new questions (rate limit). What is stored has phone numbers, emails and addresses removed. Repeats are
 * grouped by a normalised key (lower case, punctuation stripped) and counted. A failure to save (for example a table
 * that has not been migrated yet) never breaks the chat: Luma simply does not say the question was saved.
 */
final class UnansweredQuestions
{
    public const MIN_LENGTH = 3;
    public const MAX_LENGTH = 200;

    /** New questions one visitor may save per hour. Repeats of a question already in the table do not use the allowance. */
    public const LIMIT_PER_HOUR = 8;

    /** Tagalog words that are profanity whole, and stems that are profanity with any ending ("tanginang", "putangina mo"). */
    private const TAGALOG_PROFANITY = ['puta', 'gago', 'bobo', 'ulol', 'pakyu', 'kantot', 'burat', 'lintik', 'buwisit', 'pokpok'];
    private const TAGALOG_STEMS = ['putangina', 'putang ina', 'tangina', 'tang ina', 'pakshet', 'tarantado'];

    private const TLDS = 'com|net|org|ph|io|co|me|info|biz|xyz|ly|gg|tv|app|site|online|shop|store|link|click|top|dev|us|uk|ru|cn|tk|ml|ga|cf|gq|cc|ws|to|fm|ai';

    /** Saves (or counts again) a question. True only when it is in the table afterwards. */
    public static function record(string $message): bool
    {
        try {
            // personal details come out first: an email address contains a domain, but it is removed, not a reason to skip
            if (mb_strlen(trim($message)) > self::MAX_LENGTH) {
                return false;
            }

            $clean = self::redact($message);
            $key   = self::key($clean);

            if (mb_strlen($key) < self::MIN_LENGTH || self::skipReason($clean) !== null) {
                return false;
            }

            return DB::transaction(function () use ($clean, $key) {
                $row = LumaUnansweredQuestion::where('question_key', $key)->lockForUpdate()->first();

                if ($row) {
                    $row->times_asked++;
                    $row->last_asked_at = now();
                    // asked again after the team handled it: it is open again
                    $row->dismissed_at = null;
                    $row->taught_at    = null;
                    $row->save();

                    return true;
                }

                // A new row costs one slot of the visitor's hourly allowance.
                if (!RateLimiter::attempt(self::limiterKey(), self::LIMIT_PER_HOUR, fn () => true, 3600)) {
                    return false;
                }

                LumaUnansweredQuestion::create([
                    'question_key'   => $key,
                    'question'       => mb_substr($clean, 0, self::MAX_LENGTH),
                    'times_asked'    => 1,
                    'first_asked_at' => now(),
                    'last_asked_at'  => now(),
                ]);

                return true;
            });
        } catch (\Throwable $e) {
            Log::warning('Luma could not save an unanswered question: ' . $e->getMessage());

            return false;
        }
    }

    /** Why a message must not be stored, or null when it may be. */
    public static function skipReason(string $message): ?string
    {
        $text = trim($message);
        $len  = mb_strlen($text);

        if ($len < self::MIN_LENGTH) {
            return 'too_short';
        }
        if ($len > self::MAX_LENGTH) {
            return 'too_long';
        }
        if (self::hasLink($text)) {
            return 'link';
        }
        if (self::isProfane($text)) {
            return 'profanity';
        }
        if (self::isGibberish($text)) {
            return 'gibberish';
        }

        return null;
    }

    public static function hasLink(string $text): bool
    {
        return (bool) preg_match('~(?:https?|ftp)://|www\.|[a-z0-9\-]+\.(?:' . self::TLDS . ')(?![a-z])~i', $text);
    }

    public static function isProfane(string $text): bool
    {
        $plain = ' ' . Text::plain($text) . ' ';
        foreach (self::TAGALOG_PROFANITY as $word) {
            if (str_contains($plain, ' ' . $word . ' ')) {
                return true;
            }
        }
        foreach (self::TAGALOG_STEMS as $stem) {
            if (str_contains($plain, ' ' . $stem)) {
                return true;
            }
        }

        return Validator::make(['q' => $text], ['q' => [new NoInappropriateTerms(), Profanity::make()]])->fails();
    }

    /** Repeated characters, keyboard mashing, no real words, or mostly not letters. */
    public static function isGibberish(string $text): bool
    {
        if (preg_match('/(.)\1{4,}/su', $text)) {
            return true;
        }
        $letters = preg_match_all('/\p{L}/u', $text);
        if ($letters < self::MIN_LENGTH || $letters / max(1, mb_strlen($text)) < 0.5) {
            return true;
        }

        $words = preg_split('/[^\p{L}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        $real  = 0;
        foreach ($words as $w) {
            $len = mb_strlen($w);
            if ($len > 25 || preg_match('/(asdf|qwer|zxcv|hjkl|sdfg|dfgh|wert|erty|rtyu|tyui|yuio|uiop|xcvb|cvbn|vbnm)/', $w)) {
                return true;
            }
            // five or more letters without a vowel is not a word in English or Tagalog
            if ($len >= 5 && !preg_match('/[aeiouy]/', $w)) {
                return true;
            }
            if (preg_match('/[bcdfghjklmnpqrstvwxz]{6,}/', $w)) {
                return true;
            }
            if ($len >= 2) {
                $real++;
            }
        }

        return $real === 0;
    }

    /** Phone numbers, emails and street addresses taken out of the text. */
    public static function redact(string $text): string
    {
        $text = preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)*\.[A-Za-z]{2,}/', ' ', $text) ?? $text;
        $text = preg_replace('/(?<!\w)\+?\(?\d[\d\s().\-]{5,}\d(?!\w)/', ' ', $text) ?? $text;
        $text = preg_replace('/\b\d{1,5}\s+(?:[\p{L}.\-]+\s+){1,3}(?:street|st|ave|avenue|road|rd|blvd|drive)\b\.?(?:\s+\p{L}+){0,3}/iu', ' ', $text) ?? $text;
        $text = preg_replace('/\b(?:brgy|barangay|purok|sitio|blk|block|lot|phase|street|st|ave|avenue|road|rd|subd|subdivision|village|zone)\b\.?\s*#?[\p{L}\p{N}.\-]*(?:\s+[\p{L}\p{N}.\-]+){0,3}/iu', ' ', $text) ?? $text;
        $text = preg_replace('/#\s?\d+[\p{L}\p{N}.\-]*(?:\s+\p{L}+){0,3}/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    /** Lower case, punctuation turned into spaces, spaces collapsed: "Do you SELL pianos??" and "do you sell pianos" are one question. */
    public static function key(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? $text;

        return mb_substr(trim(preg_replace('/\s+/u', ' ', $text) ?? ''), 0, 200);
    }

    /**
     * Called when an intent is added or edited: every open question that the intent's keywords now match is marked taught
     * and leaves the admin list.
     */
    public static function markTaughtBy(string $keywords): int
    {
        if (trim($keywords) === '') {
            return 0;
        }

        $min   = (float) config('luma.min_score', 2.0);
        $taken = 0;

        LumaUnansweredQuestion::open()->orderBy('id')->chunkById(200, function ($rows) use ($keywords, $min, &$taken) {
            foreach ($rows as $row) {
                if (IntentMatcher::score(Text::words($row->question), $keywords)['score'] >= $min) {
                    $row->forceFill(['taught_at' => now()])->save();
                    $taken++;
                }
            }
        });

        return $taken;
    }

    /** Dismissed and taught questions older than the given days (default 30) are deleted (or only counted on a dry run). */
    public static function purge(bool $dryRun = false, int $days = 30): int
    {
        $q = LumaUnansweredQuestion::query()->where(function ($w) use ($days) {
            $w->where('dismissed_at', '<', now()->subDays($days))->orWhere('taught_at', '<', now()->subDays($days));
        });

        return $dryRun ? $q->count() : $q->delete();
    }

    private static function limiterKey(): string
    {
        $who = Auth::check() ? 'u' . Auth::id() : 's' . (session()->getId() ?: 'none');

        return 'luma-unanswered:' . $who . ':' . (request()?->ip() ?? 'cli');
    }
}
