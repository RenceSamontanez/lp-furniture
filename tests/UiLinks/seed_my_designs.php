<?php
/**
 * My Designs: SCRATCH design requests for the demo customer (ready, ordered, favorited, failed, still generating) using the
 * AI images already in public/images/ai-designs (read only, nothing is copied). Refuses to run unless the database name ends in _test.
 *   php tests/UiLinks/seed_my_designs.php
 */
putenv('DB_DATABASE=furniture_db_test');
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'furniture_db_test';
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = (string) config('database.connections.' . config('database.default') . '.database');
if (!str_ends_with($db, '_test')) { fwrite(STDERR, "REFUSING: {$db}\n"); exit(99); }

use App\Models\AiDesignSuggestion;
use App\Models\CustomDesignRequest;
use App\Models\User;

$customer = User::where('email', json_decode(file_get_contents($root . '/storage/app/screenshot-login.json'), true)['customer_email'])->firstOrFail();
if (CustomDesignRequest::where('user_id', $customer->id)->exists()) { echo "designs already there\n"; exit(0); }

$images = array_values(array_filter(scandir($root . '/public/images/ai-designs'), fn ($f) => str_starts_with($f, 'ai-design-backfill-')));
$ago = fn (string $when) => now()->modify($when);

// [description, style, status, extra, image index|null, favorited, created]
$rows = [
    ['A modern minimalist bookshelf in walnut, floor-to-ceiling, open-back shelving.', null, 'pending', [], null, false, '-10 minutes'],
    ['Create a modern entertainment center made from premium solid Narra wood with a sophisticated and luxurious appearance.', 'Modern', 'ai_generated', [], 0, true, '-3 hours'],
    ['A rustic oak dining table with turned legs and a distressed finish, seats six.', 'Rustic', 'ai_generated', [], 1, false, '-1 day'],
    ['A modern minimalist bookshelf in walnut, floor-to-ceiling, open-back shelving.', null, 'rejected', ['failure_reason' => 'timeout'], null, false, '-3 days'],
    ['Design a modern front entrance door made from premium solid Narra wood. The door should feature a clean minimalist design with vertical wooden panels, two narrow frosted glass side panels and a brushed-metal handle. A very long description follows so the scroll box inside the preview has something to scroll: the handle sits at 1.0 meter, the glass is frosted, the frame is solid, and the finish is a natural matte oil that shows the grain of the wood without darkening it too much.', 'Contemporary', 'approved', ['pipeline_status' => 'submitted'], 2, true, '-5 days'],
    ['A rustic oak dining table with turned legs and a distressed finish, seats six.', 'Rustic', 'rejected', ['failure_reason' => 'provider_error'], null, false, '-2 months'],
    ['Queen bed frame with a tall carved headboard in mahogany.', 'Classic', 'ai_generated', [], 3, false, '-2 months'],
    ['Round coffee table with a live-edge top and tapered legs.', 'Natural', 'ai_generated', [], 0, false, '-3 months'],
];

foreach ($rows as [$desc, $style, $status, $extra, $img, $fav, $when]) {
    $r = new CustomDesignRequest();
    $r->forceFill(array_merge([
        'user_id' => $customer->id, 'title' => 'Custom Furniture', 'furniture_type' => 'Furniture', 'description' => $desc,
        'style_preference' => $style, 'status' => $status, 'created_at' => $ago($when), 'updated_at' => $ago($when),
    ], $extra))->save();
    if ($img !== null) {
        AiDesignSuggestion::create(['design_id' => $r->id, 'image_path' => 'images/ai-designs/' . $images[$img], 'is_favorited' => $fav, 'generated_at' => $ago($when)]);
    }
}
echo "seeded " . count($rows) . " designs\n";
