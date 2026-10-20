<?php

/**
 * Adds three sample chairs to the SCRATCH database so the Luma browser walk (tests/Luma/browser-9a.cjs) can show a product-card
 * reply. Refuses to run unless the database name ends in _test.   php tests/Luma/seed_9a.php
 */

putenv('DB_DATABASE=furniture_db_test');
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'furniture_db_test';

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$db = (string) config('database.connections.' . config('database.default') . '.database');
if (!str_ends_with($db, '_test')) {
    fwrite(STDERR, "REFUSING: database is '{$db}', not a _test database\n");
    exit(99);
}

foreach ([['Rocking Chair', 5500, null], ['Executive Office Chair', 7200, 6400], ['Dining Chair', 2300, null]] as [$name, $price, $sale]) {
    App\Models\Product::firstOrCreate(
        ['product_name' => $name],
        ['category' => 'Chair', 'price' => $price, 'sale_price' => $sale, 'stock_quantity' => 8, 'product_status' => 'available', 'description' => 'Sample chair for the Luma screenshots.']
    );
}
echo "sample chairs ready in {$db}\n";
