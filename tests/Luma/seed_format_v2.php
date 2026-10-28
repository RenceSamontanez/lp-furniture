<?php

/**
 * Answer format v2 browser walk: SCRATCH data only. Refuses to run unless the database name ends in _test.
 *
 *   DB_DATABASE=furniture_db_test php artisan migrate --force
 *   php tests/Luma/seed_format_v2.php            (--clean removes the products and the unanswered questions it made)
 *
 * Creates: Luma's built-in answers, the 20 hand-seeded legacy rows reworded by the real refresh, Contact Settings with every
 * field set, six catalog products with real photos (copied into storage/app/public/product-photos), a demo admin
 * (storage/app/screenshot-login.json, git-ignored) and a few unanswered questions for the admin list.
 */
putenv('DB_DATABASE=furniture_db_test');
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'furniture_db_test';
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$db = (string) config('database.connections.' . config('database.default') . '.database');
if (!str_ends_with($db, '_test')) {
    fwrite(STDERR, "REFUSING: {$db}\n");
    exit(99);
}

use App\Models\BusinessSetting;
use App\Models\ChatbotIntent;
use App\Models\LumaUnansweredQuestion;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Support\Luma\DefaultIntents;

if (in_array('--clean', $argv, true)) {
    ProductImage::query()->delete();
    Product::query()->delete();
    LumaUnansweredQuestion::query()->delete();
    echo "cleaned\n";
    exit(0);
}

$this_cmd = fn (string $c) => Illuminate\Support\Facades\Artisan::call($c);
$this_cmd('chatbot:sync-defaults');

$previous = json_decode(file_get_contents($root . '/database/data/luma-previous-defaults.json'), true);
foreach ($previous['__legacy'] as $old) {
    // as on the live database: these hand-seeded rows are OLDER than the built-in ones (the matcher prefers the newer row on a tie)
    $row = ChatbotIntent::firstOrNew(['keywords' => $old['keywords']]);
    if (!$row->exists) {
        $row->response = $old['response'];
        $row->is_active = true;
        $row->created_at = $row->updated_at = now()->subMonths(3);
        $row->timestamps = false;
        $row->save();
    }
}
echo count(DefaultIntents::refreshUntouched()) . " rows reworded\n";

$bs = BusinessSetting::query()->first() ?? new BusinessSetting();
$bs->fill(['phone' => '0991-239-4770', 'email' => 'shop@example.test', 'facebook_url' => 'https://facebook.com/lpfurnituresj', 'shop_address' => 'Sitio Pulo Ilaya, Calit-calit, San Juan, Batangas'])->save();

$photos = ['narra-dining-table-front.jpg' => ['Narra Dining Table', 'Dining Table', 42500], 'study-table.jpg' => ['Study Table', 'Table', 8900],
    'sofa-frame.jpg' => ['Sofa Frame', 'Sofa', 21000], 'king-bed-frame.jpg' => ['King Bed Frame', 'Bed', 17500],
    'executive-office-chair.jpg' => ['Executive Office Chair', 'Chair', 6200], 'rocking-chair.jpg' => ['Rocking Chair', 'Chair', 5400]];
@mkdir($root . '/storage/app/public/product-photos', 0777, true);
if (Product::count() === 0) {
    foreach ($photos as $file => [$name, $cat, $price]) {
        $dest = 'product-photos/v2-' . $file;
        copy($root . '/public/images/products/' . $file, $root . '/storage/app/public/' . $dest);
        $p = Product::create(['product_name' => $name, 'category' => $cat, 'price' => $price, 'stock_quantity' => 5, 'product_status' => 'available', 'product_image' => $dest]);
        ProductImage::create(['product_id' => $p->id, 'image_path' => $dest, 'is_primary' => true, 'sort_order' => 0]);
    }
}

$pw = bin2hex(random_bytes(8));
$admin = User::firstOrNew(['email' => 'walk-admin@example.test']);
$admin->forceFill(['first_name' => 'Walk', 'last_name' => 'Admin', 'password' => Illuminate\Support\Facades\Hash::make($pw), 'role' => 'admin',
    'is_validated' => true, 'account_status' => 'active', 'email_verified_at' => now()])->save();
file_put_contents($root . '/storage/app/screenshot-login.json', json_encode(['admin_email' => $admin->email, 'customer_email' => $admin->email]));

foreach ([['do you sell pianos', 7], ['do you make coffins', 3], ['are you hiring carpenters', 1]] as [$q, $n]) {
    LumaUnansweredQuestion::updateOrCreate(['question_key' => $q], ['question' => $q, 'times_asked' => $n, 'first_asked_at' => now()->subDays(3), 'last_asked_at' => now()->subHours($n)]);
}
echo "seeded\n";
