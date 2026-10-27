<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Progress photos an admin sends with an order update: storage/app/public/order-update-photos (public disk, git-ignored,
 * random names). The customer sees them on Track My Order and in the email, so they are public by URL like product photos.
 * Validation is UploadedImage::rules() (real JPEG/PNG, 5 MB); storing resizes to 1600 px.
 */
final class OrderUpdatePhotos
{
    public const DIR = 'order-update-photos';

    public static function disk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk('public');
    }

    /** Saves a validated upload and returns "order-update-photos/name.ext". */
    public static function store(UploadedFile $file): string
    {
        UploadedImage::optimize($file);
        $name = Str::random(24) . '.' . UploadedImage::extensionOf($file);
        self::disk()->putFileAs(self::DIR, $file, $name);

        return self::DIR . '/' . $name;
    }

    public static function delete(?string $path): void
    {
        if ($path !== null && str_starts_with($path, self::DIR . '/') && !str_contains($path, '..')) {
            self::disk()->delete($path);
        }
    }
}
