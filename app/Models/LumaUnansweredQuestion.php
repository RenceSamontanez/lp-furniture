<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class LumaUnansweredQuestion extends Model
{
    protected $fillable = ['question_key', 'question', 'times_asked', 'first_asked_at', 'last_asked_at', 'dismissed_at', 'taught_at'];

    protected $casts = [
        'first_asked_at' => 'datetime',
        'last_asked_at'  => 'datetime',
        'dismissed_at'   => 'datetime',
        'taught_at'      => 'datetime',
    ];

    /** Still waiting for the admin: not dismissed and not taught. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('dismissed_at')->whereNull('taught_at');
    }
}
