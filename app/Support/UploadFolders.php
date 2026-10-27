<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The folders customers' uploads live in, and how to tell whether a file in them still has a database row.
 *
 * This is the single list `data:clear-test`, `storage:cleanup` and the System health page work from. Nothing outside
 * these folders is ever deleted, and none of them holds product or admin assets:
 *   - product photos are in storage/app/public/product-photos (not listed here; delete() and orphans() also refuse them
 *     explicitly), the old backup in public/images/products is not listed either
 *   - page art (hero, gallery, payments logos, icons, luma) is in the other public/images/* folders (not listed)
 *   - public/images/ai-designs holds only customer AI concepts (its .gitignore keeps them out of the repository)
 */
final class UploadFolders
{
    /** key => [root, relative folder, label, column check] */
    private const FOLDERS = [
        'design-references' => ['private', 'design-references', 'Customer reference photos (private)'],
        'validations'       => ['private', 'validations', 'Identity photos (private)'],
        'service-photos'    => ['private', 'service-photos', 'Other Services ticket photos (private)'],
        'agreements'        => ['private', 'agreements', 'Agreement image copies (private)'],
        'receipts'          => ['private', 'receipts', 'Old payment receipts (private, legacy)'],
        'legacy-references' => ['public_disk', 'design-references', 'Old public reference photos (storage/app/public)'],
        'ai-designs'        => ['public', 'images/ai-designs', 'AI design concepts (public/images/ai-designs)'],
        'order-update-photos' => ['public_disk', 'order-update-photos', 'Order progress photos (storage/app/public)'],
    ];

    /** Temporary files: removed by storage:cleanup once old, never counted as "orphans". */
    private const TEMP = [
        'tmp-private' => ['private', 'tmp', 'Temporary files (private)'],
        'tmp-public'  => ['public_disk', 'tmp', 'Temporary files (public disk)'],
    ];

    /** Folders that look like uploads but are NOT cleared, shown on the dry run so it is clear they are kept. */
    public const KEPT_FOLDERS = [
        'storage/app/public/product-photos' => 'Product photos (main and extra, managed by the admin)',
        'public/images/products' => 'Old product photo backup (never deleted)',
        'public/images/hero, hero-ai, gallery, about, icons, payments, luma, customize' => 'Page art, logos and style photos',
    ];

    /** @return array<string, array{path: string, label: string, root: string, rel: string}> */
    public static function folders(bool $withTemp = false): array
    {
        $out = [];
        foreach (self::FOLDERS + ($withTemp ? self::TEMP : []) as $key => [$root, $rel, $label]) {
            $out[$key] = [
                'path'  => self::norm(config("maintenance.roots.{$root}") . '/' . $rel),
                'label' => $label,
                'root'  => self::norm((string) config("maintenance.roots.{$root}")),
                'rel'   => $rel,
            ];
        }

        return $out;
    }

    public static function norm(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    /** @return list<string> absolute, normalised paths of every real file (dotfiles like .gitignore are skipped). */
    public static function files(string $key): array
    {
        $folder = (self::folders(true)[$key] ?? null)['path'] ?? null;
        if ($folder === null || !is_dir($folder)) {
            return [];
        }

        $files = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $file) {
            if ($file->isFile() && !str_starts_with($file->getFilename(), '.')) {
                $files[] = self::norm($file->getPathname());
            }
        }
        sort($files);

        return $files;
    }

    /** @return array{files: int, bytes: int} */
    public static function usage(string $key): array
    {
        $files = self::files($key);

        return ['files' => count($files), 'bytes' => array_sum(array_map(fn ($f) => (int) @filesize($f), $files))];
    }

    /** Whether a database row still points at this file. */
    public static function isReferenced(string $key, string $absolutePath): bool
    {
        $folders = self::folders(true);
        $rel = ltrim(substr($absolutePath, strlen($folders[$key]['root'])), '/');
        $name = basename($absolutePath);

        return match ($key) {
            'design-references', 'legacy-references' => DB::table('custom_design_requests')->where('reference_image', $rel)->exists(),
            'validations'    => DB::table('user_validations')->where(fn ($q) => $q->where('id_photo_path', $rel)->orWhere('selfie_photo_path', $rel))->exists(),
            'service-photos' => DB::table('service_inquiries')->where('reference_photos', 'like', '%' . self::like($name) . '%')->exists(),
            'agreements'     => DB::table('design_agreements')->where('terms_snapshot', 'like', '%' . self::like($name) . '%')->exists(),
            'receipts'       => DB::table('payments')->where('receipt_image_path', 'like', '%' . self::like($name) . '%')->exists(),
            'ai-designs'     => DB::table('ai_design_suggestions')->where('image_path', 'like', '%' . self::like($name) . '%')->exists(),
            'order-update-photos' => DB::table('order_status_events')->where('photo_path', $rel)->exists(),
            default          => true,   // unknown folder: treat as referenced, never delete
        };
    }

    /** True for anything inside a product-photos folder or the old public/images/products backup. These are never deleted by the tools. */
    public static function isProductPhoto(string $path): bool
    {
        $p = self::norm($path);

        return str_contains($p, '/product-photos/') || str_contains($p, '/images/products/');
    }

    private static function like(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
    }

    /**
     * Files with no database row that are older than $olderThanHours.
     *
     * @return array<string, list<string>> key => absolute paths
     */
    public static function orphans(?int $olderThanHours = null): array
    {
        $hours = $olderThanHours ?? (int) config('maintenance.orphan_age_hours', 24);
        $cutoff = time() - $hours * 3600;
        $out = [];

        foreach (array_keys(self::folders()) as $key) {
            foreach (self::files($key) as $file) {
                if ((int) @filemtime($file) <= $cutoff && !self::isProductPhoto($file) && !self::isReferenced($key, $file)) {
                    $out[$key][] = $file;
                }
            }
        }

        return $out;
    }

    /** @return list<string> temporary files older than $olderThanHours */
    public static function expiredTemp(int $olderThanHours = 24): array
    {
        $cutoff = time() - $olderThanHours * 3600;
        $out = [];
        foreach (array_keys(self::TEMP) as $key) {
            foreach (self::files($key) as $file) {
                if ((int) @filemtime($file) <= $cutoff) {
                    $out[] = $file;
                }
            }
        }

        return $out;
    }

    /** Deletes one file, but only when it really sits inside one of the listed folders. */
    public static function delete(string $absolutePath): bool
    {
        $real = realpath($absolutePath);
        if ($real === false || !is_file($real)) {
            return false;
        }
        $real = self::norm($real);
        if (self::isProductPhoto($real)) {
            return false;
        }

        foreach (self::folders(true) as $f) {
            $base = realpath($f['path']);
            if ($base !== false && str_starts_with($real, self::norm($base) . '/')) {
                return @unlink($real);
            }
        }

        return false;
    }

    /** Removes empty sub-folders left behind (never the listed folders themselves). */
    public static function pruneEmptyDirs(): void
    {
        foreach (self::folders(true) as $f) {
            if (!is_dir($f['path'])) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($f['path'], \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $item) {
                if ($item->isDir() && count(scandir($item->getPathname())) === 2) {
                    @rmdir($item->getPathname());
                }
            }
        }
    }

    public static function human(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $i => $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return ($i === 0 ? (string) $bytes : number_format($bytes, 1)) . ' ' . $unit;
            }
            $bytes /= 1024;
        }

        return '';
    }
}
