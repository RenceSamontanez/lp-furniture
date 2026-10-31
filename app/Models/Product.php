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

    /**
     * Length x Width x Height in cm. The stored text has always been "A x B x C cm"; A was
     * labelled Width and B Depth, so it is read as Length and Width with the numbers unchanged.
     * Null when there is no size or the text is not in that form.
     *
     * @return array{length: float, width: float, height: float}|null
     */
    public function getDimensionsCmAttribute(): ?array
    {
        return \App\Support\Dimensions::legacy($this->dimensions);
    }

    /** The three numbers for the admin form, in cm and without trailing zeros ('' when there is no size). */
    public function getDimensionFormValuesAttribute(): array
    {
        $d = $this->dimensions_cm;
        $out = [];
        foreach (['length', 'width', 'height'] as $k) {
            $out[$k] = $d ? \App\Support\Dimensions::number($d[$k]) : '';
        }

        return $out;
    }

    /** "120 x 60 x 75 cm (47.2 x 23.6 x 29.5 in)", or whatever text was stored if it is not in that form, or null. */
    public function getDimensionsLabelAttribute(): ?string
    {
        if (blank($this->dimensions)) {
            return null;
        }

        $d = $this->dimensions_cm;

        return $d ? \App\Support\Dimensions::cmWithInches($d['length'], $d['width'], $d['height']) : (string) $this->dimensions;
    }

    public function materialEstimate()
    {
        return $this->hasOne(ProductMaterialEstimate::class);
    }

    /** Most extra photos (beyond the main one) the admin may attach to one product. */
    public const MAX_EXTRA_IMAGES = 5;

    /** Length, width, height in cm, or null when no usable size is stored. Alias of dimensions_cm for the shop pages. */
    public function getSizeCmAttribute(): ?array
    {
        return $this->dimensions_cm;
    }

    /** The same size in inches, one decimal (cm / 2.54), worked out here and never stored. Null when there is no size. */
    public function getSizeInchesAttribute(): ?array
    {
        $d = $this->dimensions_cm;
        if (!$d) {
            return null;
        }

        return array_map(fn ($cm) => round($cm / 2.54, 1), $d);
    }

    /** Photos other than the main one, in order. */
    public function extraImages()
    {
        return $this->hasMany(ProductImage::class)->where('is_primary', false)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Ordered photo URLs: the main photo first (the primary gallery row; no legacy-column fallback),
     * then the extras. Empty when the product has no photo at all.
     *
     * @return list<string>
     */
    public function getGalleryImagesAttribute(): array
    {
        $rows = $this->relationLoaded('images') ? $this->images : $this->images()->get();

        $urls = [];
        $primary = $rows->firstWhere('is_primary', true);
        if ($primary) {
            $urls[] = $primary->url;
        }

        foreach ($rows->where('is_primary', false)->sortBy([['sort_order', 'asc'], ['id', 'asc']]) as $row) {
            $urls[] = $row->url;
        }

        return $urls;
    }

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

    /** The main photo row (the admin's photo), or null. Uses a loaded relation when there is one. */
    public function mainPhoto(): ?ProductImage
    {
        if ($this->relationLoaded('primaryImage')) {
            return $this->primaryImage;
        }
        if ($this->relationLoaded('images')) {
            return $this->images->firstWhere('is_primary', true);
        }

        return $this->primaryImage()->first();
    }

    public function getHasPhotoAttribute(): bool
    {
        return $this->mainPhoto() !== null;
    }

    /** The one source for a product's picture in every view: the admin's main photo, else the "Image coming soon" placeholder. */
    public function getImageUrlAttribute(): string
    {
        return $this->mainPhoto()?->url ?? \App\Support\ProductPhotos::placeholderUrl();
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