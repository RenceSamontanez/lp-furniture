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
}