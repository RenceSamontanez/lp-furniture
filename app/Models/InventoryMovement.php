<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One line of the inventory history. Written only by App\Support\MaterialStock; never edited. */
class InventoryMovement extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['inventory_id', 'material_name', 'type', 'quantity_change', 'quantity_after', 'order_id', 'user_id', 'note'];

    protected $casts = ['quantity_change' => 'float', 'quantity_after' => 'float'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
