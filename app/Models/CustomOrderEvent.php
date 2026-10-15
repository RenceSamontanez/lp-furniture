<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One timeline entry for a custom order (append-only by convention —
 * no update or delete routes). Feeds the customer and admin timelines.
 */
class CustomOrderEvent extends Model
{
    public $timestamps = false;

    public const ACTOR_CUSTOMER = 'customer';
    public const ACTOR_ADMIN    = 'admin';
    public const ACTOR_SYSTEM   = 'system';

    protected $fillable = [
        'request_id',
        'order_id',
        'type',
        'actor_type',
        'actor_id',
        'data',
        'occurred_at',
    ];

    protected $casts = [
        'data'        => 'array',
        'occurred_at' => 'datetime',
    ];

    public function request()
    {
        return $this->belongsTo(CustomDesignRequest::class, 'request_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
