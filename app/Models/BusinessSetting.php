<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * The one source of truth for business contact info (single row).
 * Read it with BusinessSetting::current() — cached forever, and the
 * cache is cleared whenever the row is saved.
 */
class BusinessSetting extends Model
{
    public const CACHE_KEY = 'business_settings.current';

    // Values used if the row is somehow missing, so pages never break
    public const DEFAULTS = [
        'phone'        => '0917-000-0000',
        'email'        => 'contact@example.com',
        'facebook_url' => 'https://facebook.com/your-page',
    ];

    protected $fillable = [
        'phone',
        'email',
        'facebook_url',
        'updated_by',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    public static function current(): self
    {
        // Cache the plain attributes rather than the model object
        $attributes = Cache::rememberForever(self::CACHE_KEY, function () {
            return static::query()->orderBy('id')->first()?->getAttributes();
        });

        if (!$attributes) {
            return new static(self::DEFAULTS);
        }

        return (new static())->newFromBuilder($attributes);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // Phone stripped to digits (and a leading +) for tel: links
    public function getPhoneHrefAttribute(): string
    {
        return 'tel:' . preg_replace('/[^\d+]/', '', $this->phone);
    }

    /**
     * Messenger link (m.me/{page}) derived from the Facebook page URL.
     * Falls back to the page URL itself when no page name/ID can be
     * read from it (e.g. a group link, which has no Messenger inbox).
     */
    public function getMessengerUrlAttribute(): string
    {
        $url   = $this->facebook_url;
        $host  = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path  = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $parts = $path === '' ? [] : explode('/', $path);

        if ($host === 'm.me') {
            return $url;
        }

        $page = null;

        if (($parts[0] ?? null) === 'profile.php') {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $page = isset($query['id']) && ctype_digit((string) $query['id']) ? $query['id'] : null;
        } elseif (($parts[0] ?? null) === 'pages') {
            // facebook.com/pages/Some-Name/123456789
            $last = end($parts);
            $page = ctype_digit((string) $last) ? $last : null;
        } elseif (isset($parts[0]) && !in_array($parts[0], ['groups', 'events', 'people', 'share', 'watch'], true)) {
            $page = $parts[0];
        }

        return $page !== null ? 'https://m.me/' . rawurlencode($page) : $url;
    }

    /**
     * Swap {{phone}}, {{email}}, {{facebook}} in a chatbot response for
     * the current values. The chatbot widget renders replies as HTML,
     * so the substituted values are escaped.
     */
    public static function applyPlaceholders(string $text): string
    {
        $settings = static::current();

        return str_replace(
            ['{{phone}}', '{{email}}', '{{facebook}}'],
            [e($settings->phone), e($settings->email), e($settings->facebook_url)],
            $text
        );
    }
}
