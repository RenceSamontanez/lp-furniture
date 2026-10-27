<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'product_id',
        'product_name',
        'product_image',
        'quantity',
        'unit_price',
        'subtotal',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'subtotal'   => 'decimal:2',
    ];

    /**
     * The picture for past-order pages: the live product's main photo, else the photo name saved on the line when
     * it was ordered (new "product-photos/…" path, or an old file that is still in public/images/products), else the placeholder.
     */
    public function getImageUrlAttribute(): string
    {
        if ($this->product && $this->product->has_photo) {
            return $this->product->image_url;
        }

        $snap = (string) $this->product_image;
        if ($snap !== '' && !str_contains($snap, '..')) {
            if (\App\Support\ProductPhotos::isManaged($snap) && \App\Support\ProductPhotos::disk()->exists($snap)) {
                return \App\Support\ProductPhotos::url($snap);
            }
            if (!str_contains($snap, '/') && is_file(\App\Support\ProductPhotos::legacyDir() . '/' . $snap)) {
                return asset('images/products/' . $snap);
            }
        }

        return \App\Support\ProductPhotos::placeholderUrl();
    }

    // Belongs to an order
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    // Belongs to a product
    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}