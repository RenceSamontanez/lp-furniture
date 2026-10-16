<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatbotIntent extends Model
{
    protected $fillable = [
        'keywords',
        'response',
        'suggestions',
        'category',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

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
