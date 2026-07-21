<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Delivery extends Model
{
    protected $table = 'delivery';

    protected $fillable = [
        'order_id',
        'delivery_address',
        'contact_number',
        'scheduled_date',
        'delivered_at',
        'delivery_notes',
        'status',
    ];

    protected $casts = [
        'scheduled_date' => 'date',
        'delivered_at'   => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function isDelivered(): bool
    {
        return $this->status === 'delivered';
    }
}