<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductImage;
use App\Support\ProductPhotos;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportBuiltinProductPhotos extends Command
{
    protected $signature = 'products:import-builtin-photos
        {--force : Really copy the files and repoint the rows. Without it this only prints what WOULD happen}';

    protected $description = 'Copies every product photo that still lives in public/images/products into storage/app/public/product-photos (resized and re-encoded by the upload rules) and repoints product_images and products.product_image. The original files are never deleted. Dry run by default; safe to run twice.';

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $legacy = ProductPhotos::legacyDir();
        $this->line(($force ? 'APPLYING' : 'DRY RUN (nothing is changed; add --force to apply)') . "  database: " . DB::connection()->getDatabaseName());
        $this->line("From: {$legacy}   To: " . ProductPhotos::disk()->path(ProductPhotos::DIR) . "\n");

        $totals = ['products' => 0, 'copy' => 0, 'skipped' => 0, 'problems' => 0];

        foreach (Product::with('images')->orderBy('id')->get() as $product) {
            $plan = $this->plan($product, $legacy);
            $this->line("#{$product->id} {$product->product_name}");

            if ($plan['steps'] === [] && !$plan['fixColumn']) {
                $this->line('    nothing to do' . ($plan['problems'] ? '' : ' (no old-style photos)'));
                foreach ($plan['problems'] as $p) {
                    $this->warn("    ! {$p}");
                }
                $totals['problems'] += count($plan['problems']);
                $totals['skipped']++;
                continue;
            }

            foreach ($plan['steps'] as $s) {
                $role = $s['primary'] ? 'MAIN ' : 'extra';
                $this->line("    {$role} " . ($s['row'] ? 'repoint' : 'add    ') . "  {$s['src']}  ->  product-photos/<new random name>");
            }
            if ($plan['fixColumn']) {
                $this->line('    products.product_image  ->  the main photo\'s new path');
            }
            foreach ($plan['problems'] as $p) {
                $this->warn("    ! {$p}");
            }
            $totals['problems'] += count($plan['problems']);
            $totals['products']++;
            $totals['copy'] += count($plan['steps']);

            if ($force) {
                $this->apply($product, $plan, $legacy);
            }
        }

        $this->line("\n{$totals['products']} product(s) " . ($force ? 'imported' : 'would be imported') . ", {$totals['copy']} file(s) " . ($force ? 'copied' : 'to copy') . ", {$totals['skipped']} untouched, {$totals['problems']} problem(s).");
        $this->line('The originals in public/images/products are left exactly as they are.');

        return self::SUCCESS;
    }

    /**
     * @return array{steps: list<array{row: ?ProductImage, src: string, primary: bool, sort: int}>, fixColumn: bool, problems: list<string>}
     */
    private function plan(Product $product, string $legacy): array
    {
        $steps = [];
        $problems = [];
        $rows = $product->images->sortBy([['sort_order', 'asc'], ['id', 'asc']])->values();
        $usedSources = [];

        foreach ($rows as $row) {
            if (ProductPhotos::isManaged($row->image_path)) {
                continue;
            }
            $src = basename($row->image_path);
            if (!is_file($legacy . '/' . $src)) {
                $problems[] = "file missing in public/images/products: {$src} (row left alone)";
                continue;
            }
            $steps[] = ['row' => $row, 'src' => $src, 'primary' => (bool) $row->is_primary, 'sort' => (int) $row->sort_order];
            $usedSources[$src] = true;
        }

        // The old products.product_image file, when no row shows it (Narra's admin upload): imported as an extra so nothing is lost.
        $col = (string) $product->product_image;
        if ($col !== '' && !ProductPhotos::isManaged($col) && !isset($usedSources[basename($col)]) && !$rows->contains(fn ($r) => basename($r->image_path) === basename($col))) {
            if (!is_file($legacy . '/' . basename($col))) {
                $problems[] = "file missing in public/images/products: {$col} (products.product_image left alone)";
            } else {
                $hasMain = $rows->contains(fn ($r) => $r->is_primary);
                $steps[] = ['row' => null, 'src' => basename($col), 'primary' => !$hasMain, 'sort' => (int) $rows->max('sort_order') + 1];
            }
        }

        // After the run the column must equal the main photo's path. A main row that could not be imported blocks that.
        $mainRow = $rows->firstWhere('is_primary', true);
        $mainUnresolved = $mainRow && !ProductPhotos::isManaged($mainRow->image_path) && !collect($steps)->contains(fn ($s) => $s['row']?->id === $mainRow->id);
        $fixColumn = false;
        if (!$mainUnresolved) {
            if ($mainRow && ProductPhotos::isManaged($mainRow->image_path)) {
                $fixColumn = $product->product_image !== $mainRow->image_path;
            } elseif ($steps !== [] && ($mainRow || collect($steps)->contains(fn ($s) => $s['primary']))) {
                $fixColumn = true;
            }
        }

        return ['steps' => $steps, 'fixColumn' => $fixColumn, 'problems' => $problems];
    }

    private function apply(Product $product, array $plan, string $legacy): void
    {
        $created = [];
        try {
            $copies = [];   // source name => new path, so one old file used twice is copied once
            foreach ($plan['steps'] as $i => $s) {
                if (!isset($copies[$s['src']])) {
                    $new = ProductPhotos::importFile($legacy . '/' . $s['src']);
                    if (str_starts_with($new, '!')) {
                        $this->warn("    ! {$s['src']}: " . substr($new, 1) . ' (skipped)');
                        $copies[$s['src']] = null;
                        continue;
                    }
                    $copies[$s['src']] = $new;
                    $created[] = $new;
                }
            }

            DB::transaction(function () use ($product, $plan, $copies) {
                $mainPath = null;
                foreach ($plan['steps'] as $s) {
                    $path = $copies[$s['src']] ?? null;
                    if ($path === null) {
                        continue;
                    }
                    if ($s['row']) {
                        $s['row']->update(['image_path' => $path]);
                    } else {
                        ProductImage::create([
                            'product_id' => $product->id, 'image_path' => $path, 'label' => $s['primary'] ? 'Main' : null,
                            'sort_order' => $s['sort'], 'is_primary' => $s['primary'],
                        ]);
                    }
                    if ($s['primary']) {
                        $mainPath = $path;
                    }
                }
                $main = $product->images()->where('is_primary', true)->first();
                if ($main && ProductPhotos::isManaged($main->image_path) && $product->product_image !== $main->image_path) {
                    $product->update(['product_image' => $main->image_path]);
                }
            });
        } catch (\Throwable $e) {
            foreach ($created as $c) {
                ProductPhotos::disk()->delete($c);
            }
            $this->error("    failed, this product was left as it was: {$e->getMessage()}");
        }
    }
}
