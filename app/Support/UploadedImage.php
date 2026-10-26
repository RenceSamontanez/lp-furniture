<?php

namespace App\Support;

use App\Rules\RealJpegOrPng;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * The one rule for every image a person uploads: a real JPEG or PNG (the bytes are inspected, not the file name),
 * 5 MB at most, and nothing larger than 1600 px on its longest side is kept (a big photo is shrunk and
 * re-saved at quality 82; a small one is left byte-for-byte alone).
 *
 * Uploads that use it: admin product main + extra photos, customer design reference photo, identity ID + selfie,
 * Other Services ticket photos. (The AI generator's own output is not an upload and is validated in GenerateAiDesign.)
 */
final class UploadedImage
{
    public const MAX_KB = 5120;
    public const MAX_EDGE = 1600;
    public const QUALITY = 82;

    /** A JPEG at or under this size that already fits in 1600 px is not re-encoded. */
    private const KEEP_JPEG_BELOW_BYTES = 1_500_000;

    /** Validation rules for one image field (add 'nullable'/'required' yourself). */
    public static function rules(): array
    {
        return ['file', 'max:' . self::MAX_KB, new RealJpegOrPng()];
    }

    public static function canResize(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatefromjpeg') && function_exists('imagecreatefrompng');
    }

    /**
     * Shrinks/compresses the uploaded temp file in place when it is too large. Returns true when the file was rewritten.
     * Never throws and never leaves a half-written file: on any problem the original upload is kept as it is.
     */
    public static function optimize(UploadedFile $file): bool
    {
        $path = $file->getRealPath();
        if (!$path) {
            return false;
        }

        $mime = RealJpegOrPng::mimeOf($path);
        if ($mime === null) {
            return false;
        }

        if (!self::canResize()) {
            Log::warning('Image upload kept at full size: the PHP gd extension is not loaded, so it cannot be resized.');

            return false;
        }

        try {
            return self::resizeFile($path, $mime);
        } catch (\Throwable $e) {
            Log::warning('Image upload kept at full size: ' . $e->getMessage());

            return false;
        }
    }

    /** Resizes the file at $path in place. */
    public static function resizeFile(string $path, string $mime): bool
    {
        $info = @getimagesize($path);
        if (!$info) {
            return false;
        }
        [$w, $h] = $info;

        $orientation = 1;
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($path);
            $orientation = (int) ($exif['Orientation'] ?? 1);
        }
        $needsRotate = in_array($orientation, [3, 6, 8], true);

        $tooBig = max($w, $h) > self::MAX_EDGE;
        $heavyJpeg = $mime === 'image/jpeg' && filesize($path) > self::KEEP_JPEG_BELOW_BYTES;
        if (!$tooBig && !$heavyJpeg) {
            return false;
        }

        $src = $mime === 'image/png' ? @imagecreatefrompng($path) : @imagecreatefromjpeg($path);
        if (!$src) {
            return false;
        }

        if ($needsRotate) {
            $rotated = imagerotate($src, [3 => 180, 6 => -90, 8 => 90][$orientation], 0);
            if ($rotated) {
                imagedestroy($src);
                $src = $rotated;
                $w = imagesx($src);
                $h = imagesy($src);
            }
        }

        $scale = $tooBig ? self::MAX_EDGE / max($w, $h) : 1;
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $dst = imagecreatetruecolor($nw, $nh);
        if ($mime === 'image/png') {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        $tmp = $path . '.opt';
        $ok = $mime === 'image/png' ? imagepng($dst, $tmp, 7) : imagejpeg($dst, $tmp, self::QUALITY);
        imagedestroy($src);
        imagedestroy($dst);

        if (!$ok || !is_file($tmp) || filesize($tmp) === 0) {
            @unlink($tmp);

            return false;
        }

        // Keep the original when re-encoding did not help and the size did not need to change.
        if (!$tooBig && filesize($tmp) >= filesize($path)) {
            @unlink($tmp);

            return false;
        }

        return @rename($tmp, $path) || (copy($tmp, $path) && @unlink($tmp));
    }

    /** '.jpg' or '.png' from the real bytes (never from the client's file name). */
    public static function extensionOf(UploadedFile $file): string
    {
        return RealJpegOrPng::mimeOf((string) $file->getRealPath()) === 'image/png' ? 'png' : 'jpg';
    }
}
