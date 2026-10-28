<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One line of an order's history. See App\Support\OrderTracking for the only code that writes these. */
class OrderStatusEvent extends Model
{
    public const UPDATED_AT = null;

    public const TYPES = ['placed', 'payment', 'stage', 'update', 'correction', 'cancel', 'stock'];

    protected $fillable = [
        'order_id', 'admin_user_id', 'type', 'title', 'from_status', 'to_status',
        'note', 'photo_path', 'customer_notified',
    ];

    protected $casts = [
        'customer_notified' => 'boolean',
        'created_at'        => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    public function photoUrl(): ?string
    {
        return $this->photo_path ? asset('storage/' . ltrim($this->photo_path, '/')) : null;
    }

    /** Only what the customer was told. Corrections are never notified, so they never appear. */
    public function scopeForCustomer($query)
    {
        return $query->where('customer_notified', true)->where('type', '!=', 'correction');
    }
}
