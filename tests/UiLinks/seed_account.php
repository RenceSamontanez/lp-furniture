<?php
/**
 * My Account walk: SCRATCH rows only (refuses to run unless the database name ends in _test).
 * Imports the real places (docs/data/psgc-2019.json), then makes five customers and a product, and prints their emails as JSON:
 *   fresh   email account, nothing saved, identity not submitted, password never changed (Available)
 *   saved   verified, saved address (Lipa City), password changed 2 days ago (locked)
 *   noaddr  verified, phone but no address, a cart item (order details: "none yet")
 *   google  Google-only account, last identity attempt rejected
 *   pending verified=false, identity under review, an old single-line address only ("Previous address")
 *
 *   DB_DATABASE=furniture_db_test php artisan migrate:fresh --force
 *   php tests/UiLinks/seed_account.php > storage/app/account-seed.json
 */
putenv('DB_DATABASE=furniture_db_test');
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'furniture_db_test';
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = (string) config('database.connections.' . config('database.default') . '.database');
if (!str_ends_with($db, '_test')) { fwrite(STDERR, "REFUSING: {$db}\n"); exit(99); }

use App\Models\Barangay;
use App\Models\CityMunicipality;
use App\Models\Product;
use App\Models\Province;
use App\Models\User;
use App\Models\UserValidation;
use App\Support\IdentityPhotos;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

if (Province::count() === 0) {
    Artisan::call('places:import');
}

$lipa = CityMunicipality::where('name', 'Lipa City')->whereHas('province', fn ($q) => $q->where('name', 'Batangas'))->firstOrFail();
$brgy = Barangay::where('city_municipality_id', $lipa->id)->where('name', 'like', 'Marauoy%')->firstOrFail();

$mk = function (string $key, string $first, string $last, array $extra = []) {
    $u = new User();
    $u->forceFill(array_merge([
        'first_name' => $first, 'last_name' => $last, 'email' => "acct-{$key}@example.test", 'password' => 'Walk-Pass-123',
        'role' => 'customer', 'account_status' => 'active', 'is_validated' => false, 'email_verified_at' => now()->subDays(30),
        'phone_number' => '09171234567', 'phone_e164' => '+639171234567', 'phone_country' => 'PH', 'created_at' => now()->subDays(40),
    ], $extra))->save();
    return $u;
};

$fresh = $mk('fresh', 'Maria', 'Reyes', ['phone_number' => null, 'phone_e164' => null, 'phone_country' => null]);
$saved = $mk('saved', 'Maria', 'Reyes', [
    'is_validated' => true, 'password_changed_at' => now()->subDays(2),
    'address_province_id' => $lipa->province_id, 'address_city_id' => $lipa->id, 'address_barangay_id' => $brgy->id,
    'address_street' => 'Blk 5 Lot 12, Sampaguita St.', 'address_postal_code' => '4217', 'address_landmark' => 'Near the chapel',
]);
$noaddr = $mk('noaddr', 'Juan', 'Dela Cruz', ['is_validated' => true]);
$google = $mk('google', 'Ana', 'Santos', ['google_id' => 'g-walk', 'password' => null]);
$pending = $mk('pending', 'Pedro', 'Garcia', ['address' => 'Purok 3, San Jose, Batangas']);

$p = Product::create([
    'product_name' => 'Acacia Side Table', 'price' => 6200, 'stock_quantity' => 20, 'category' => 'Tables',
    'description' => 'Solid acacia side table.', 'product_image' => 'images/products/placeholder.jpg', 'status' => 'available',
]);
foreach ([$noaddr, $saved] as $u) {
    DB::table('cart_items')->insert(['user_id' => $u->id, 'product_id' => $p->id, 'quantity' => 1, 'added_at' => now(), 'updated_at' => now()]);
}

// stand-in pictures (not real IDs) for the pending submission
$disk = Storage::disk(IdentityPhotos::DISK);
$idPath = "validations/{$pending->id}/id-walk.jpg"; $sPath = "validations/{$pending->id}/selfie-walk.jpg";
$disk->put($idPath, file_get_contents($root . '/public/images/hero-ai/ai-1.jpg')); $disk->put($sPath, file_get_contents($root . '/public/images/hero-ai/ai-3.jpg'));
UserValidation::create(['user_id' => $pending->id, 'attempt_number' => 1, 'id_photo_path' => $idPath, 'selfie_photo_path' => $sPath, 'status' => 'pending', 'submitted_at' => now()->subHour()]);

$rej = new UserValidation();
$rej->forceFill(['user_id' => $google->id, 'attempt_number' => 1, 'id_photo_path' => 'validations/x/id.jpg', 'selfie_photo_path' => 'validations/x/selfie.jpg',
    'status' => 'rejected', 'rejection_reason' => 'Photo is blurry', 'submitted_at' => now()->subDay(), 'reviewed_at' => now()->subHours(3)])->save();

echo json_encode(['password' => 'Walk-Pass-123', 'users' => ['fresh' => $fresh->email, 'saved' => $saved->email, 'noaddr' => $noaddr->email, 'google' => $google->email, 'pending' => $pending->email],
                  'ids' => ['pending_validation' => $pending->id], 'places' => ['province' => $lipa->province_id, 'city' => $lipa->id, 'barangay' => $brgy->id]]);
