<?php
/**
 * Sets the SCRATCH contact settings for the footer browser walk. Refuses to run unless the database name ends in _test.
 *   php tests/UiLinks/footer-settings.php set     (phone, email, Facebook, TikTok and map coordinates filled; sample values, not real ones)
 *   php tests/UiLinks/footer-settings.php blank   (everything empty: the footer then shows the config/shop.php fallbacks, and no TikTok button)
 */
putenv('DB_DATABASE=furniture_db_test');
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'furniture_db_test';
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = (string) config('database.connections.' . config('database.default') . '.database');
if (!str_ends_with($db, '_test')) { fwrite(STDERR, "REFUSING: {$db}\n"); exit(99); }

use App\Models\BusinessSetting;

$row = BusinessSetting::query()->orderBy('id')->first() ?? new BusinessSetting();
$values = ($argv[1] ?? 'set') === 'blank'
    ? ['phone' => '', 'email' => '', 'facebook_url' => '', 'tiktok_url' => null, 'shop_address' => null, 'shop_landmarks' => null, 'map_lat' => null, 'map_lng' => null]
    : ['phone' => '0917 123 4567', 'email' => 'hello@lpfurniture.test', 'facebook_url' => 'https://facebook.com/lpfurniture', 'tiktok_url' => 'https://www.tiktok.com/@lpfurniture', 'shop_address' => null, 'shop_landmarks' => null, 'map_lat' => '13.8216000', 'map_lng' => '121.3965000'];
$row->forceFill($values)->save();
echo "footer settings: " . ($argv[1] ?? 'set') . "\n";
