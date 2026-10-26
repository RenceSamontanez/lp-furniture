<?php

namespace App\Support;

use App\Models\UserValidation;
use App\Rules\RealJpegOrPng;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Where identity photos (government ID, selfie) live and how they are sent.
 * They sit on the private disk and are only ever sent by an admin-authorised or owner-checked route:
 * no public URL exists. Every response is no-store and only goes out if the bytes are a real JPEG or PNG.
 */
final class IdentityPhotos
{
    public const DISK = 'private';

    public static function store(UploadedFile $file, int $userId, string $kind): string
    {
        return $file->store("validations/{$userId}/{$kind}", self::DISK);
    }

    public static function pathFor(UserValidation $v, string $type): ?string
    {
        return $type === 'id' ? $v->id_photo_path : ($type === 'selfie' ? $v->selfie_photo_path : null);
    }

    /** 404 unless the file exists on the private disk and is really a JPEG or PNG. */
    public static function response(UserValidation $v, string $type)
    {
        $path = self::pathFor($v, $type);

        if (!$path || str_contains($path, '..') || str_starts_with($path, '/') || !Storage::disk(self::DISK)->exists($path)) {
            abort(404);
        }

        $absolute = Storage::disk(self::DISK)->path($path);
        $mime = RealJpegOrPng::mimeOf($absolute);
        if ($mime === null) {
            abort(404);
        }

        return response()->file($absolute, [
            'Content-Type'           => $mime,
            'Content-Disposition'    => 'inline; filename="' . ($type === 'id' ? 'government-id' : 'selfie') . ($mime === 'image/png' ? '.png' : '.jpg') . '"',
            'Cache-Control'          => 'no-store, private, max-age=0',
            'Pragma'                 => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
