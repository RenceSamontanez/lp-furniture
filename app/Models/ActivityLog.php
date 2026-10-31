<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One row of the Activity Log. Written only through App\Support\ActivityLog::record(). */
class ActivityLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'actor_name', 'role', 'event', 'kind', 'category', 'description',
        'subject_type', 'subject_id', 'subject_label', 'created_at',
    ];

    protected $casts = ['created_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
