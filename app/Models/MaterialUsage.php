<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaterialUsage extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'inventory_id',
        'quantity_used',
        'notes',
    ];

    protected $casts = [
        'quantity_used' => 'decimal:2',
        'used_at'       => 'datetime',
    ];

    // Belongs to an order
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    // Belongs to an inventory item
    public function inventory()
    {
        return $this->belongsTo(Inventory::class);
    }
}