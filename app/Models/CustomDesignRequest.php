<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomDesignRequest extends Model
{
    // How long a request can sit in 'pending' (still generating)
    // before it's considered stuck rather than just slow — a normal
    // run finishes in well under a minute.
    public const STALE_AFTER_MINUTES = 10;

    protected $fillable = [
        'user_id',
        'title',
        'furniture_type',
        'description',
        'budget_min',
        'budget_max',
        'preferred_material',
        'preferred_finish',
        'style_preference',
        'dimensions',
        'reference_image',
        'ai_prompt',
        'status',
        'failure_reason',
        'admin_notes',
        'admin_contacted_at',
    ];

    protected $casts = [
        'admin_contacted_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function suggestions()
    {
        return $this->hasMany(AiDesignSuggestion::class, 'design_id');
    }

    // "Has an image a customer can actually see" — distinct from
    // "has suggestion rows at all". A request can have rows whose
    // image_path is an external URL from before every provider was
    // required to save locally (see AiDesignSuggestion::isLoadable()),
    // which should be treated the same as having no image.
    public function hasLoadableSuggestion(): bool
    {
        return $this->suggestions->contains(fn (AiDesignSuggestion $s) => $s->isLoadable());
    }

    public function loadableSuggestions()
    {
        return $this->suggestions->filter(fn (AiDesignSuggestion $s) => $s->isLoadable());
    }

    public static function failureReasonLabels(): array
    {
        return [
            'timeout'        => 'Took too long to generate',
            'provider_error' => 'Our AI providers were unavailable',
            'invalid_image'  => "The generated image didn't come through properly",
        ];
    }

    public function failureReasonLabel(): string
    {
        return self::failureReasonLabels()[$this->failure_reason]
            ?? 'We were unable to generate this design';
    }

    // Shared by the design:fail-stale command and the lazy check run
    // on page load (CustomDesignController) — both need to apply the
    // exact same rule, so it lives in one place rather than two copies
    // that could drift. GenerateAiDesign checks for this status change
    // on every loop iteration and discards its own result if it finds
    // one, so a job that's still actually running when this fires
    // can't flip the status back.
    public static function failStale(): int
    {
        $stale = self::where('status', 'pending')
            ->where('created_at', '<=', now()->subMinutes(self::STALE_AFTER_MINUTES))
            ->get();

        foreach ($stale as $request) {
            $request->update(['status' => 'rejected', 'failure_reason' => 'timeout']);

            Notification::create([
                'user_id' => $request->user_id,
                'title'   => 'Design Generation Failed',
                'message' => 'Your design request took too long to generate and was marked as failed. Please try again.',
                'type'    => 'general',
                'url'     => route('design.index'),
                'is_read' => false,
            ]);
        }

        return $stale->count();
    }

    /* ── custom order flow ── */

    public function quotes()
    {
        return $this->hasMany(CustomQuote::class, 'request_id');
    }

    public function callLogs()
    {
        return $this->hasMany(CustomOrderCallLog::class, 'request_id');
    }

    public function agreements()
    {
        return $this->hasMany(DesignAgreement::class, 'request_id');
    }

    public function events()
    {
        return $this->hasMany(CustomOrderEvent::class, 'request_id');
    }

    public function selectedSuggestion()
    {
        return $this->belongsTo(AiDesignSuggestion::class, 'selected_suggestion_id');
    }
}