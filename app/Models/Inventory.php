<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Inventory extends Model
{
    protected $table = 'inventory';

    public $timestamps = false;

    protected $fillable = [
        'material_name',
        'material_quantity',
        'unit',
        'low_stock_threshold',
        'inventory_status',
    ];

    protected $casts = [
        'material_quantity'   => 'decimal:2',
        'low_stock_threshold' => 'decimal:2',
        'last_updated'        => 'datetime',
    ];

    public function usageRecords()
    {
        return $this->hasMany(MaterialUsage::class);
    }

    public function isLow(): bool
    {
        return $this->inventory_status === 'low_stock';
    }

    public function isOut(): bool
    {
        return $this->inventory_status === 'out_of_stock';
    }
}