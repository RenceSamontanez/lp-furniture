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
        'furniture_type_other',
        'description',
        'budget_min',
        'budget_max',
        'preferred_material',
        'wood_special_details',
        'wood_supply',
        'preferred_finish',
        'style_preference',
        'dimensions',
        'dim_length_cm',
        'dim_width_cm',
        'dim_height_cm',
        'dimension_unit',
        'reference_image',
        'ai_prompt',
        'status',
        'failure_reason',
        'admin_notes',
        'admin_contacted_at',
        // custom order flow (stage 3: customer submission)
        'pipeline_status',
        'request_mode',
        'modification_notes',
        'selected_suggestion_id',
        'contact_phone',
        'contact_email',
        'preferred_call_window',
        'submitted_at',
    ];

    protected $casts = [
        'admin_contacted_at' => 'datetime',
        'submitted_at'       => 'datetime',
        'payment_due_at'     => 'datetime',
    ];

    // Customer-facing labels; keys match the preferred_call_window ENUM.
    public const CALL_WINDOWS = [
        'morning_8_12'   => 'Morning, 8 AM to 12 PM',
        'afternoon_12_5' => 'Afternoon, 12 PM to 5 PM',
        'anytime'        => 'Anytime, 8 AM to 5 PM',
    ];

    public const REQUEST_MODES = [
        'exact'    => 'Build exactly this design',
        'modified' => 'Build it with some changes',
    ];

    /**
     * Length x Width x Height in cm: the new columns, or the old "Width x Depth x Height" text
     * read as Length x Width x Height. Null when the customer gave no size.
     *
     * @return array{length: float, width: float, height: float}|null
     */
    public function getDimensionsCmAttribute(): ?array
    {
        if ($this->dim_length_cm !== null && $this->dim_width_cm !== null && $this->dim_height_cm !== null) {
            return ['length' => (float) $this->dim_length_cm, 'width' => (float) $this->dim_width_cm, 'height' => (float) $this->dim_height_cm];
        }

        return \App\Support\Dimensions::legacy($this->dimensions);
    }

    /** "47.2 x 23.6 x 29.5 in (120 x 60 x 75 cm)", or null when there is no size. */
    public function getDimensionsLabelAttribute(): ?string
    {
        $d = $this->dimensions_cm;

        return $d ? \App\Support\Dimensions::triplet($d['length'], $d['width'], $d['height'], $this->dimension_unit) : null;
    }

    /** "Mahogany", or the special request in words. */
    public function getWoodLabelAttribute(): string
    {
        return \App\Support\WoodChoices::label($this->preferred_material, $this->wood_special_details, $this->wood_supply);
    }

    public function getIsSpecialWoodAttribute(): bool
    {
        return \App\Support\WoodChoices::isSpecial($this->preferred_material);
    }

    public function getSuppliesOwnWoodAttribute(): bool
    {
        return $this->is_special_wood && $this->wood_supply === \App\Support\WoodChoices::SUPPLY_CUSTOMER;
    }

    /** For a sentence: "chair", "pergola", or "custom furniture" when there is no type. */
    public function getFurnitureTypeNounAttribute(): string
    {
        return \App\Support\FurnitureTypes::noun($this->furniture_type, $this->furniture_type_other) ?? ($this->furniture_type ?: 'custom furniture');
    }

    /** "Chair", "Other: pergola" or "Not specified" (requests made before the type was required). */
    public function getFurnitureTypeLabelAttribute(): string
    {
        return \App\Support\FurnitureTypes::label($this->furniture_type, $this->furniture_type_other);
    }

    /** "Custom Furniture — Rence": the name shown for this custom order everywhere (the stored title is not rewritten). */
    public function getOrderNameAttribute(): string
    {
        return \App\Support\CustomOrderName::for($this->user);
    }

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

    // Requests that have at least one stored generated image (a cheap pre-filter; hasLoadableSuggestion() still decides "ready").
    public function scopeWithGeneratedImage($query)
    {
        return $query->whereHas('suggestions', fn ($q) => $q->where('image_path', 'like', '%images/ai-designs/%'));
    }

    /**
     * A customer's newest successfully generated designs, newest first, for the Recent strip on the Customize page.
     * Two queries in total (the requests, then all their suggestions), never one per thumbnail.
     *
     * @return \Illuminate\Support\Collection<int, array{request: self, suggestion: AiDesignSuggestion}>
     */
    public static function recentReadyFor(int $userId, int $limit = 3)
    {
        return static::where('user_id', $userId)
            ->withGeneratedImage()
            ->with('suggestions')
            ->latest()->latest('id')
            ->limit($limit * 4)   // spare rows: a stored path whose file is gone is not "ready"
            ->get()
            ->map(fn (self $r) => ['request' => $r, 'suggestion' => $r->designState() === 'ready' ? $r->representativeSuggestion() : null])
            ->filter(fn ($e) => $e['suggestion'] !== null)
            ->take($limit)
            ->values();
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

    // Sent to the team: either through the stage 3 form (pipeline_status
    // set) or, for older requests, the one-click flow that only set
    // status 'approved'. Either way the design is locked: no regenerate,
    // no second submission. A different design means a new request.
    public function isSentToTeam(): bool
    {
        if ($this->isLegacyUnsent()) {
            return false;
        }

        return $this->pipeline_status !== null
            || in_array($this->status, ['approved', 'converted_to_order'], true);
    }

    // A request sent the old one-click way (status approved, no pipeline)
    // that no admin has touched yet: it never got contact details, so it
    // may open the "Send to our team" form once.
    public function isLegacyUnsent(): bool
    {
        return $this->pipeline_status === null
            && $this->status === 'approved'
            && $this->admin_contacted_at === null;
    }

    // Before a quote is sent the customer may still change their request.
    public function isEditableByCustomer(): bool
    {
        return in_array($this->pipeline_status, ['submitted', 'contacting', 'unreachable'], true);
    }

    // The picture to show the team: the selected concept if it can be
    // loaded, else the customer's own reference image, else null.
    public function previewImageUrl(bool $forAdmin = false): ?string
    {
        $selected = $this->selectedSuggestion;
        if ($selected && $selected->isLoadable()) {
            return $selected->displayUrl();
        }

        $chosen = $this->suggestions->firstWhere('is_selected', true);
        if ($chosen && $chosen->isLoadable()) {
            return $chosen->displayUrl();
        }

        if (\App\Support\ReferencePhoto::locate($this->reference_image)) {
            return $forAdmin ? route('admin.reference-photo', $this->id) : route('design.reference', $this->id);
        }

        return null;
    }

    /* ── My Designs page ── */

    // ready | generating | failed, the same rule the History tab always used:
    // an image that can actually be loaded wins over the stored status.
    public function designState(): string
    {
        if ($this->hasLoadableSuggestion()) {
            return 'ready';
        }

        return $this->status === 'pending' ? 'generating' : 'failed';
    }

    // The concept shown for this request: the selected one, else the first loadable.
    public function representativeSuggestion(): ?AiDesignSuggestion
    {
        $loadable = $this->loadableSuggestions();

        return $loadable->firstWhere('is_selected', true) ?? $loadable->first();
    }

    public function styleLabel(): string
    {
        return $this->style_preference ?: 'No preference';
    }

    // Short name for the preview box: the start of the description.
    public function designName(): string
    {
        $text = trim(preg_replace('/\s+/', ' ', (string) $this->description));

        return $text === '' ? 'Custom furniture design' : \Illuminate\Support\Str::words($text, 7, '…');
    }

    // Today / This week / Earlier, used to group the History tab.
    public function historyGroup(): string
    {
        if ($this->created_at->isToday()) {
            return 'Today';
        }

        return $this->created_at->greaterThanOrEqualTo(today()->subDays(6)) ? 'This week' : 'Earlier';
    }

    // "Today, 9:41 AM", "Yesterday, 8:15 AM", "3 days ago", "Oct 16, 2026".
    public function whenLabel(): string
    {
        $t = $this->created_at;
        if ($t->isToday()) {
            return 'Today, ' . $t->format('g:i A');
        }
        if ($t->isYesterday()) {
            return 'Yesterday, ' . $t->format('g:i A');
        }

        return $t->greaterThanOrEqualTo(today()->subDays(6)) ? $t->diffForHumans() : $t->format('M j, Y');
    }

    // What the preview box and the action button need for one Ready design.
    public function previewPayload(AiDesignSuggestion $suggestion): array
    {
        $ordered = $this->isSentToTeam();

        return [
            'requestId'    => $this->id,
            'suggestionId' => $suggestion->id,
            'image'        => $suggestion->displayUrl(),
            'name'         => $this->designName(),
            'style'        => $this->styleLabel(),
            'created'      => $this->created_at->format('M j, Y, g:i A'),
            'description'  => (string) $this->description,
            'ordered'      => $ordered,
            'favorited'    => (bool) $suggestion->is_favorited,
            'orderUrl'     => route('design.select', $this->id),
            'conceptUrl'   => route('design.show', $this->id),
        ];
    }

    public function callWindowLabel(): ?string
    {
        return self::CALL_WINDOWS[$this->preferred_call_window] ?? null;
    }

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