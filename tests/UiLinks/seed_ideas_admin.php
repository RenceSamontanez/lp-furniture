<?php
/**
 * Customize part 2 walk: SCRATCH data for the admin screens. Ideas to review rows in every status and kind, plus a submitted custom
 * request with a reference photo (private disk) and "I will provide the wood". Refuses to run unless the database name ends in _test.
 *   php tests/UiLinks/seed_ideas_admin.php
 */
putenv('DB_DATABASE=furniture_db_test');
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'furniture_db_test';
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!str_ends_with((string) config('database.connections.' . config('database.default') . '.database'), '_test')) { fwrite(STDERR, "REFUSING\n"); exit(99); }

use App\Models\AiDesignSuggestion;
use App\Models\CustomDesignRequest;
use App\Models\UnansweredIdea;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

if (UnansweredIdea::count() === 0) {
    $rows = [
        ['furniture_type', 'Pergola', 7, 'new', null], ['furniture_type', 'Gazebo', 4, 'answered', 'We build gazebos from Acacia. Our team will confirm the size by phone.'],
        ['wood', 'Teak', 9, 'new', null], ['wood', 'Narra', 12, 'answered', 'Narra is hard to get. Most customers provide their own Narra. We can also quote a purchase, but it takes longer.'],
        ['idea', 'secret jewelry compartment', 3, 'new', null], ['idea', 'glass top', 5, 'added_as_option', null],
        ['furniture_type', 'Crib', 2, 'dismissed', null], ['idea', 'built-in bluetooth speaker', 1, 'new', null],
        ['furniture_type', 'Staircase railing', 3, 'new', null], ['wood', 'Molave', 2, 'new', null],
    ];
    foreach ($rows as $i => [$kind, $text, $n, $status, $answer]) {
        $r = UnansweredIdea::record($kind, $text);
        $r->forceFill(['times_asked' => $n, 'status' => $status, 'answer' => $answer, 'answered_at' => $answer ? now() : null,
            'previous_status' => $status === 'dismissed' ? 'new' : null, 'first_seen_at' => now()->subDays(12 - $i), 'last_seen_at' => now()->subHours($i * 5)])->save();
    }
}

$customer = User::where('email', json_decode(file_get_contents($root . '/storage/app/screenshot-login.json'), true)['customer_email'])->firstOrFail();
if (!CustomDesignRequest::where('user_id', $customer->id)->whereNotNull('pipeline_status')->whereNotNull('reference_image')->exists()) {
    $images = array_values(array_filter(scandir($root . '/public/images/ai-designs'), fn ($f) => str_starts_with($f, 'ai-design-backfill-')));
    $src = $root . '/public/images/hero-ai/ai-1.jpg';
    $path = 'design-references/' . $customer->id . '/seed-reference.jpg';
    Storage::disk('local')->put($path, file_get_contents($src));

    $r = new CustomDesignRequest();
    $r->forceFill([
        'user_id' => $customer->id, 'title' => 'Custom order', 'furniture_type' => 'Other', 'furniture_type_other' => 'Staircase railing',
        'description' => 'A solid wood railing for a curved staircase, natural matte finish, with square posts.', 'status' => 'approved',
        'preferred_material' => 'Special request', 'wood_special_details' => 'Old narra beams', 'wood_supply' => 'customer',
        'style_preference' => 'Rustic', 'reference_image' => $path, 'pipeline_status' => 'submitted', 'request_mode' => 'exact',
        'contact_phone' => '09171234567', 'contact_email' => 'customer@example.test', 'preferred_call_window' => 'morning_8_12', 'submitted_at' => now()->subHours(3),
    ])->save();
    $s = AiDesignSuggestion::create(['design_id' => $r->id, 'image_path' => 'images/ai-designs/' . $images[0], 'generated_at' => now(), 'is_selected' => true]);
    $r->forceFill(['selected_suggestion_id' => $s->id])->save();
    echo "request {$r->id}\n";
}
echo "ok\n";
