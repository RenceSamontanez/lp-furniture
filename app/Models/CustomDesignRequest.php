<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomDesignRequest extends Model
{
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
        'admin_notes',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function suggestions()
    {
        return $this->hasMany(AiDesignSuggestion::class, 'design_id');
    }
}