<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiDesignSuggestion extends Model
{
    public $timestamps = false; // table only has generated_at, not created_at/updated_at

    protected $fillable = [
        'design_id',
        'image_path',
        'ai_model_used',
        'prompt_used',
        'is_selected',
        'is_favorited',
        'customer_notes',
        'generated_at',
    ];

    protected $casts = [
        'is_selected'  => 'boolean',
        'generated_at' => 'datetime',
    ];

    public function request()
    {
        return $this->belongsTo(CustomDesignRequest::class, 'design_id');
    }

    // Every provider now downloads and saves its image under
    // public/images/ai-designs before creating a row (see
    // GenerateAiDesign::saveValidatedImage()), so a currently-valid
    // row is always a local file. Rows from before that fix hold a
    // provider's own URL directly (DeepAI's output_url, Pollinations'
    // live generation endpoint) — those have no durability guarantee
    // we can vouch for, so rather than trust a remote URL that could
    // 404 or time out at any moment, any path that isn't one of our
    // own saved files is treated as not loadable.
    //
    // isLoadable() and displayUrl() both need to recognize the file
    // regardless of which of two shapes image_path is in: the current
    // one, a bare relative path ("images/ai-designs/xyz.jpg"), or an
    // older absolute one with a host baked in (asset() used to be
    // called from inside the queued job itself — a console context
    // with no incoming request, so it had nothing to derive a host
    // from and fell back to APP_URL; that value is only ever right by
    // coincidence, since the customer's browser isn't necessarily
    // talking to the app on that same host). Matching on the
    // "images/ai-designs/" substring regardless of what's in front of
    // it covers both shapes without caring which one a given row has.
    public function isLoadable(): bool
    {
        if (!$this->image_path || !str_contains($this->image_path, 'images/ai-designs/')) {
            return false;
        }

        return file_exists(public_path($this->relativeImagePath()));
    }

    // The URL to actually put in src="" — resolves a relative path
    // against the CURRENT request's own host (correct: asset() inside
    // a real HTTP request has a request to derive that host from,
    // unlike when it used to run inside the queued job). A legacy
    // absolute URL (local-with-a-stale-host, or a genuinely external
    // provider URL) is returned as-is rather than re-wrapped — calling
    // asset() on an already-absolute URL would mangle it.
    public function displayUrl(): string
    {
        if (!$this->image_path) {
            return '';
        }

        if (str_starts_with($this->image_path, 'http://') || str_starts_with($this->image_path, 'https://')) {
            return $this->image_path;
        }

        return asset($this->image_path);
    }

    private function relativeImagePath(): string
    {
        return substr($this->image_path, strpos($this->image_path, 'images/ai-designs/'));
    }
}