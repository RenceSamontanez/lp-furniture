<?php
/**
 * Identity validation walk: SCRATCH data only (refuses to run unless the database name ends in _test).
 * Creates an admin, customers in every state and photo files under storage/app/private/validations/seed-scratch/
 * (a folder of its own: remove it afterwards with `php tests/UiLinks/seed_identity.php --clean`).
 * Prints the logins as JSON.  The photos are stand-in pictures from public/images/hero-ai, not real IDs.
 *   php tests/UiLinks/seed_identity.php
 */
putenv('DB_DATABASE=furniture_db_test');
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'furniture_db_test';
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!str_ends_with((string) config('database.connections.' . config('database.default') . '.database'), '_test')) { fwrite(STDERR, "REFUSING\n"); exit(99); }

use App\Models\User;
use App\Models\UserValidation;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

$dir = 'validations/seed-scratch';
if (in_array('--clean', $argv, true)) {
    Storage::disk('private')->deleteDirectory($dir);
    echo "removed $dir\n";
    exit(0);
}

$mk = function (string $first, string $last, string $role = 'customer', bool $validated = false, string $created = '-20 days') {
    return User::updateOrCreate(['email' => str_replace(' ', '', strtolower("$first.$last")) . '@example.test'], [
        'first_name' => $first, 'last_name' => $last, 'role' => $role, 'is_validated' => $validated, 'account_status' => 'active',
        'password' => Hash::make('scratch-only-' . bin2hex(random_bytes(4))), 'phone_number' => '09170000' . rand(100, 999), 'created_at' => now()->modify($created),
    ]);
};
$photo = function (string $tag, int $n) use ($dir, $root) {
    $id = "$dir/$tag-id.jpg"; $self = "$dir/$tag-selfie.jpg";
    Storage::disk('private')->put($id, file_get_contents($root . '/public/images/hero-ai/ai-' . (($n % 2) + 1) . '.jpg'));
    Storage::disk('private')->put($self, file_get_contents($root . '/public/images/hero-ai/ai-' . (($n % 2) + 3) . '.jpg'));
    return [$id, $self];
};
$sub = function (User $u, int $attempt, string $status, string $submitted, array $extra = []) use ($photo) {
    [$id, $self] = $photo("u{$u->id}-a{$attempt}", $u->id + $attempt);
    return UserValidation::create(array_merge([
        'user_id' => $u->id, 'attempt_number' => $attempt, 'id_photo_path' => $id, 'selfie_photo_path' => $self,
        'status' => $status, 'submitted_at' => now()->modify($submitted),
    ], $extra));
};

$admin = $mk('LP', 'Admin', 'admin', true);
$all = ['name' => true, 'face' => true, 'id' => true];

$test = $mk('Test', 'User');                $sub($test, 1, 'pending', '-1 hour');
$e = $mk('Sample', 'Customer E');           $sub($e, 1, 'rejected', '-6 days', ['rejection_reason' => 'ID is expired', 'reviewed_by' => $admin->id, 'reviewed_at' => now()->modify('-5 days')]);
                                            $sub($e, 2, 'pending', '-3 hours');
$a = $mk('Sample', 'Customer A', 'customer', true); $sub($a, 1, 'approved', '-2 days', ['reviewed_by' => $admin->id, 'reviewed_at' => now()->modify('-2 days +2 hours'), 'checks_confirmed' => $all]);
$b = $mk('Sample', 'Customer B', 'customer', true); $sub($b, 1, 'rejected', '-9 days', ['rejection_reason' => 'Photo is blurry', 'reviewed_by' => $admin->id, 'reviewed_at' => now()->modify('-8 days')]);
                                            $sub($b, 2, 'approved', '-4 days', ['reviewed_by' => $admin->id, 'reviewed_at' => now()->modify('-4 days +1 hour'), 'checks_confirmed' => $all]);
$c = $mk('Sample', 'Customer C', 'customer', true); $sub($c, 1, 'approved', '-8 days', ['reviewed_by' => $admin->id, 'reviewed_at' => now()->modify('-8 days +1 hour')]); // decided before checks were recorded
$d = $mk('Sample', 'Customer D');           $sub($d, 1, 'rejected', '-5 days', ['rejection_reason' => 'Photo is blurry', 'reviewed_by' => $admin->id, 'reviewed_at' => now()->modify('-5 days +3 hours')]);
$f = $mk('Sample', 'Customer F');           $sub($f, 1, 'rejected', '-7 days', ['rejection_reason' => 'Face is not visible', 'reviewed_by' => $admin->id, 'reviewed_at' => now()->modify('-7 days +1 hour'),
                                                'follow_up_requested_at' => now()->modify('-1 day'), 'follow_up_requested_by' => $admin->id]);
$fresh = $mk('New', 'Customer');            // not submitted yet

echo json_encode([
    'admin' => $admin->email, 'fresh' => $fresh->email, 'pending' => $test->email, 'rejected' => $d->email, 'rejected_followup' => $f->email, 'approved' => $a->email,
    'ids' => ['pending_att1' => UserValidation::where('user_id', $test->id)->value('id'), 'pending_att2' => UserValidation::where('user_id', $e->id)->where('attempt_number', 2)->value('id'),
        'approved' => UserValidation::where('user_id', $a->id)->value('id'), 'approved_old' => UserValidation::where('user_id', $c->id)->value('id'),
        'rejected_latest' => UserValidation::where('user_id', $d->id)->value('id'), 'rejected_followup' => UserValidation::where('user_id', $f->id)->value('id')],
], JSON_PRETTY_PRINT) . "\n";
