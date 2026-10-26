<?php

namespace App\Support;

use App\Models\CustomDesignRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Where a customer's reference photo lives and how it is served.
 *
 * New uploads go to the private disk (storage/app/private), never under public/. Photos uploaded before that
 * change sit on the public disk and were reachable by anyone who knew the URL; `php artisan design:privatize-references`
 * moves them. Until it has run, this helper still finds them (private first, then public) so nothing breaks.
 * The photo is only ever sent by an owner-checked or admin-checked route, never by a public URL.
 */
final class ReferencePhoto
{
    public const PRIVATE_DISK = 'local';
    public const LEGACY_DISK = 'public';

    public static function store(UploadedFile $file, int $userId): string
    {
        UploadedImage::optimize($file);

        return $file->store('design-references/' . $userId, self::PRIVATE_DISK);
    }

    /** @return array{disk: string, path: string}|null */
    public static function locate(?string $path): ?array
    {
        if (!$path || str_contains($path, '..') || str_starts_with($path, '/')) {
            return null;
        }

        foreach ([self::PRIVATE_DISK, self::LEGACY_DISK] as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                return ['disk' => $disk, 'path' => $path];
            }
        }

        return null;
    }

    public static function absolutePath(?string $path): ?string
    {
        $found = self::locate($path);

        return $found ? Storage::disk($found['disk'])->path($found['path']) : null;
    }

    public static function isPublicLegacy(?string $path): bool
    {
        $found = self::locate($path);

        return $found !== null && $found['disk'] === self::LEGACY_DISK;
    }

    /** image/jpeg or image/png from the bytes, anything else (including a renamed file) is refused. */
    public static function mimeOf(string $absolutePath): ?string
    {
        $mime = @mime_content_type($absolutePath) ?: null;

        return in_array($mime, ['image/jpeg', 'image/png'], true) ? $mime : null;
    }

    public static function downloadName(CustomDesignRequest $r, string $mime): string
    {
        return 'reference-photo-request-' . $r->id . ($mime === 'image/png' ? '.png' : '.jpg');
    }
}
