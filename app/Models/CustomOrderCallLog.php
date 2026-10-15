<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One phone-call attempt about a custom order request (append-only by
 * convention — no update or delete routes).
 */
class CustomOrderCallLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'request_id',
        'admin_id',
        'attempt_no',
        'called_at',
        'answered',
        'note',
    ];

    protected $casts = [
        'attempt_no' => 'integer',
        'called_at'  => 'datetime',
        'answered'   => 'boolean',
        'created_at' => 'datetime',
    ];

    public function request()
    {
        return $this->belongsTo(CustomDesignRequest::class, 'request_id');
    }

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
