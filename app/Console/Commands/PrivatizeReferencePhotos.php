<?php

namespace App\Console\Commands;

use App\Models\CustomDesignRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * One-time: moves customers' reference photos from the public disk (reachable by URL) to the private disk.
 * Copies first, checks the copy, then deletes the public file. Safe to run again; --dry-run changes nothing.
 */
class PrivatizeReferencePhotos extends Command
{
    protected $signature = 'design:privatize-references {--dry-run : list what would move, change nothing}';

    protected $description = 'Move customer reference photos from the public disk to the private disk';

    public function handle(): int
    {
        $public = Storage::disk('public');
        $private = Storage::disk('local');
        $moved = $skipped = 0;

        CustomDesignRequest::whereNotNull('reference_image')->orderBy('id')->each(function (CustomDesignRequest $r) use ($public, $private, &$moved, &$skipped) {
            $path = $r->reference_image;
            if (!str_starts_with($path, 'design-references/') || str_contains($path, '..') || !$public->exists($path)) {
                $skipped++;

                return;
            }

            $this->line(($this->option('dry-run') ? 'would move ' : 'moving ') . $path);
            if ($this->option('dry-run')) {
                $moved++;

                return;
            }

            if (!$private->exists($path)) {
                $private->put($path, $public->get($path));
            }
            if ($private->exists($path) && $private->size($path) === $public->size($path)) {
                $public->delete($path);
                $moved++;
            } else {
                $this->error('copy check failed, left in place: ' . $path);
            }
        });

        $this->info(($this->option('dry-run') ? 'Would move ' : 'Moved ') . "{$moved}, skipped {$skipped}.");

        return self::SUCCESS;
    }
}
