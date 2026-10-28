<?php
namespace App\Models;

use App\Support\Luma\UnansweredQuestions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class ChatbotIntent extends Model
{
    protected $fillable = [
        'keywords',
        'response',
        'answer_tl',
        'suggestions',
        'category',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /** A question Luma could not answer leaves the admin list once an active intent with a matching keyword is added or edited. */
    protected static function booted(): void
    {
        static::saved(function (self $intent) {
            if ($intent->is_active && Schema::hasTable('luma_unanswered_questions')) {
                UnansweredQuestions::markTaughtBy((string) $intent->keywords);
            }
        });
    }

    // Get keywords as array
    // Usage: $intent->keywords_array
    public function getKeywordsArrayAttribute(): array
    {
        return array_map(
            'trim',
            explode(',', $this->keywords)
        );
    }

    // Get suggestions as array
    // Usage: $intent->suggestions_array
    public function getSuggestionsArrayAttribute(): array
    {
        if (!$this->suggestions) return [];
        return array_map(
            'trim',
            explode(',', $this->suggestions)
        );
    }

    // Scope — only active intents
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // Check if message matches this intent (whole words, synonyms and typos handled by Luma's matcher)
    public function matches(string $message): bool
    {
        return \App\Support\Luma\IntentMatcher::score(
            \App\Support\Luma\Text::words($message),
            (string) $this->keywords
        )['score'] >= (float) config('luma.min_score', 2.0);
    }
}
