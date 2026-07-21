<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CartItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'product_id',
        'quantity',
        'note',
    ];

    protected $casts = [
        'added_at'   => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Belongs to a user
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Belongs to a product
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    // Subtotal for this cart item
    // Usage: $cartItem->subtotal
    public function getSubtotalAttribute(): float
    {
        return $this->quantity * $this->product->price;
    }
}