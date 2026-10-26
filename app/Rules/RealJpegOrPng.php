<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * A photo must really be a JPEG or PNG: the bytes are inspected, the file name and the browser's
 * claimed type are ignored (so a PHP file named photo.jpg, an SVG, a GIF, a WebP or a PDF is refused).
 */
class RealJpegOrPng implements ValidationRule
{
    public static function mimeOf(string $absolutePath): ?string
    {
        $info = @getimagesize($absolutePath);
        $mime = $info[2] ?? null;

        $detected = @(new \finfo(FILEINFO_MIME_TYPE))->file($absolutePath) ?: null;

        if ($mime === IMAGETYPE_JPEG && $detected === 'image/jpeg') {
            return 'image/jpeg';
        }
        if ($mime === IMAGETYPE_PNG && $detected === 'image/png') {
            return 'image/png';
        }

        return null;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!$value instanceof UploadedFile || !$value->isValid() || self::mimeOf($value->getRealPath()) === null) {
            $fail('Only JPEG or PNG photos are accepted.');
        }
    }
}
