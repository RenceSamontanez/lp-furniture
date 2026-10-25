<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

/** Something a customer typed on the Customize page that we had no answer for. See the unanswered_ideas migration. */
class UnansweredIdea extends Model
{
    public const KIND_TYPE = 'furniture_type';
    public const KIND_WOOD = 'wood';
    public const KIND_IDEA = 'idea';
    public const KINDS = [self::KIND_TYPE, self::KIND_WOOD, self::KIND_IDEA];

    public const STATUS_NEW = 'new';
    public const STATUS_ANSWERED = 'answered';
    public const STATUS_ADDED = 'added_as_option';

    public const TEXT_MAX = 120;

    protected $fillable = ['kind', 'text', 'text_key', 'times_asked', 'first_seen_at', 'last_seen_at', 'status', 'answer', 'answered_at'];

    protected $casts = ['first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'answered_at' => 'datetime'];

    public static function keyFor(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text)));
    }

    /** Counts one more customer asking for this. Creates the row the first time. Returns null for empty text or an unknown kind. */
    public static function record(string $kind, string $text): ?self
    {
        $text = mb_substr(trim(preg_replace('/\s+/u', ' ', $text)), 0, self::TEXT_MAX);
        $key = self::keyFor($text);

        if ($text === '' || $key === '' || !in_array($kind, self::KINDS, true)) {
            return null;
        }

        $touch = function (self $row): self {
            $row->increment('times_asked', 1, ['last_seen_at' => now()]);

            return $row->refresh();
        };

        if ($row = static::where('kind', $kind)->where('text_key', $key)->first()) {
            return $touch($row);
        }

        try {
            return static::create([
                'kind' => $kind, 'text' => $text, 'text_key' => $key, 'times_asked' => 1,
                'first_seen_at' => now(), 'last_seen_at' => now(), 'status' => self::STATUS_NEW,
            ]);
        } catch (QueryException $e) {
            // two customers typed it at the same moment: the other insert won, count ours on its row
            $row = static::where('kind', $kind)->where('text_key', $key)->first();

            return $row ? $touch($row) : null;
        }
    }

    /** The answered (or added-as-option) entry for this exact text, or null. */
    public static function knownFor(string $kind, string $text): ?self
    {
        $key = self::keyFor($text);
        if ($key === '') {
            return null;
        }

        return static::where('kind', $kind)->where('text_key', $key)
            ->whereIn('status', [self::STATUS_ANSWERED, self::STATUS_ADDED])->first();
    }

    /** For descriptions: an answered idea whose text appears inside what the customer wrote. */
    public static function knownWithin(string $description): ?self
    {
        $haystack = ' ' . self::keyFor($description) . ' ';

        return static::where('kind', self::KIND_IDEA)->where('status', self::STATUS_ANSWERED)
            ->orderByDesc('times_asked')->get()
            ->first(fn (self $row) => str_contains($haystack, ' ' . $row->text_key . ' '));
    }
}
