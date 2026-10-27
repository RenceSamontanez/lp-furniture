<?php

namespace App\Support;

use App\Models\ProductImage;
use App\Rules\RealJpegOrPng;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The one home for product photos: storage/app/public/product-photos (public disk, served through the
 * public/storage link, git-ignored). `product_images.image_path` and `products.product_image` hold the path
 * relative to the public disk ("product-photos/AbC….jpg"). Nothing reads product photos from public/images/products
 * any more; that folder is only the untouched backup the import command copies from.
 */
final class ProductPhotos
{
    public const DIR = 'product-photos';

    public static function disk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk('public');
    }

    /** Where the old, git-tracked photos still sit (read by the import command and by old order snapshots only). */
    public static function legacyDir(): string
    {
        return rtrim((string) config('maintenance.legacy_product_photos', public_path('images/products')), '/\\');
    }

    public static function placeholderUrl(): string
    {
        return asset('images/products/placeholder.jpg');
    }

    public static function url(string $path): string
    {
        return asset('storage/' . ltrim($path, '/'));
    }

    public static function isManaged(?string $path): bool
    {
        return $path !== null && str_starts_with($path, self::DIR . '/');
    }

    /** Saves an uploaded photo (already validated) into the folder after the standard resize; returns "product-photos/name.ext". */
    public static function store(UploadedFile $file): string
    {
        UploadedImage::optimize($file);
        $name = Str::random(20) . '.' . UploadedImage::extensionOf($file);
        self::disk()->putFileAs(self::DIR, $file, $name);

        return self::DIR . '/' . $name;
    }

    /**
     * Copies a file that is not an upload (the import) through the same rules. Returns the new path, or a string
     * reason starting with "!" when the file cannot be used (not a real JPEG/PNG, still over 5 MB after resizing).
     */
    public static function importFile(string $absolute): string
    {
        $mime = RealJpegOrPng::mimeOf($absolute);
        if ($mime === null) {
            return '!not a real JPEG or PNG';
        }

        $tmp = tempnam(sys_get_temp_dir(), 'pp');
        try {
            copy($absolute, $tmp);
            UploadedImage::canResize() && UploadedImage::resizeFile($tmp, $mime);
            if (filesize($tmp) > UploadedImage::MAX_KB * 1024) {
                return '!over 5 MB after resizing';
            }
            $name = Str::random(20) . '.' . ($mime === 'image/png' ? 'png' : 'jpg');
            self::disk()->put(self::DIR . '/' . $name, file_get_contents($tmp));

            return self::DIR . '/' . $name;
        } finally {
            @unlink($tmp);
        }
    }

    /** Deletes a managed file unless some row still points at it. Never touches anything outside the folder. */
    public static function deleteIfUnused(?string $path): void
    {
        if (!self::isManaged($path) || str_contains($path, '..')) {
            return;
        }
        $used = ProductImage::where('image_path', $path)->exists()
            || \App\Models\Product::where('product_image', $path)->exists();
        if (!$used) {
            self::disk()->delete($path);
        }
    }
}
