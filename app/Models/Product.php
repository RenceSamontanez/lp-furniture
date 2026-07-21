<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'product_name',
        'category',
        'description',
        'price',
        'sale_price',
        'stock_quantity',
        'product_image',
        'material',
        'finish',
        'dimensions',
        'is_featured',
        'is_customizable',
        'product_status',
    ];

    protected $casts = [
        'price'           => 'decimal:2',
        'sale_price'      => 'decimal:2',
        'is_featured'     => 'boolean',
        'is_customizable' => 'boolean',
    ];

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function cartItems()
    {
        return $this->hasMany(CartItem::class);
    }

    public function scopeAvailable($query)
    {
        return $query->where('product_status', 'available');
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    public function getFormattedPriceAttribute(): string
    {
        return '₱' . number_format($this->price, 2);
    }

    public function getFormattedSalePriceAttribute(): ?string
    {
        return $this->sale_price ? '₱' . number_format($this->sale_price, 2) : null;
    }

    // Returns true if this product has an active discount
    public function getOnSaleAttribute(): bool
    {
        return !is_null($this->sale_price) && $this->sale_price < $this->price;
    }

    // Returns the discount percentage, e.g. 20 for 20% off
    public function getDiscountPercentAttribute(): ?int
    {
        if (!$this->on_sale) return null;
        return (int) round((($this->price - $this->sale_price) / $this->price) * 100);
    }

    public function getImageUrlAttribute(): string
    {
        // Prefer the gallery's primary image if one exists
        $primary = $this->images()->where('is_primary', true)->first();
        if ($primary) {
            return $primary->url;
        }
    
        // Fallback to legacy single-image column
        if ($this->product_image) {
            return asset('images/products/' . $this->product_image);
        }
    
        // Final fallback
        return asset('images/products/placeholder.jpg');
    }

    public function images()
{
    return $this->hasMany(ProductImage::class)->orderBy('sort_order');
}

public function primaryImage()
{
    return $this->hasOne(ProductImage::class)->where('is_primary', true);
}
}