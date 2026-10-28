<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Support\UploadedImage;
use App\Support\UploadFolders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Validator;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * data:clear-test, storage:cleanup, the upload rules and the System health page.
 * Every folder the tools touch is redirected to a temp directory (config maintenance.*), so no real upload is ever at risk.
 */
class ClearTestDataTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
    private const JPG = '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=';

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = str_replace('\\', '/', sys_get_temp_dir()) . '/lp-clear-' . bin2hex(random_bytes(4));
        foreach (['private', 'public_disk', 'public', 'logs', 'backups'] as $d) {
            mkdir($this->tmp . '/' . $d, 0777, true);
        }
        config([
            'maintenance.roots'          => ['private' => $this->tmp . '/private', 'public_disk' => $this->tmp . '/public_disk', 'public' => $this->tmp . '/public'],
            'maintenance.log_dir'        => $this->tmp . '/logs',
            'maintenance.backup_dir'     => $this->tmp . '/backups',
            'maintenance.state_file'     => $this->tmp . '/state.json',
            'maintenance.mysqldump_path' => 'none',   // no real dump during tests
        ]);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }

    private function makeFile(string $rel, string $content = 'x', ?int $ageHours = null): string
    {
        $path = $this->tmp . '/' . $rel;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $content);
        if ($ageHours !== null) {
            touch($path, time() - $ageHours * 3600);
        }

        return $path;
    }

    /** One of everything the tool touches, plus the things it must keep. @return array<string, mixed> */
    private function seedAll(): array
    {
        $admin = $this->makeUser('admin');
        $staff = $this->makeUser('staff');
        $customer = $this->makeUser('customer');
        $other = $this->makeUser('customer');

        $product = Product::create(['product_name' => 'Narra Table', 'category' => 'Tables', 'material' => 'Narra', 'finish' => 'Natural', 'price' => 5000, 'stock_quantity' => 2, 'product_status' => 'available']);
        ProductImage::create(['product_id' => $product->id, 'image_path' => 'abc.jpg', 'sort_order' => 1, 'is_primary' => true]);

        $request = $this->makeRequest($customer);
        $request->forceFill(['reference_image' => 'design-references/' . $customer->id . '/ref.png'])->save();
        $quote = $this->makeQuote($request);
        $agreement = $this->makeAgreement($quote, $customer, $admin);
        $order = $this->makeOrder($customer, ['design_request_id' => $request->id, 'design_agreement_id' => $agreement->id]);
        $this->makePayment($order);
        DB::table('notifications')->insert(['user_id' => $customer->id, 'title' => 't', 'message' => 'm', 'type' => 'general', 'is_read' => false, 'created_at' => now()]);
        DB::table('notifications')->insert(['user_id' => $admin->id, 'title' => 't', 'message' => 'm', 'type' => 'general', 'is_read' => false, 'created_at' => now()]);
        DB::table('chatbot_logs')->insert(['user_id' => $customer->id, 'session_id' => 's', 'message' => 'hi', 'response' => 'hello', 'created_at' => now()]);
        DB::table('unanswered_ideas')->insert(['kind' => 'idea', 'text' => 'test', 'text_key' => 'test', 'times_asked' => 1, 'first_seen_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('luma_unanswered_questions')->insert(['question_key' => 'do you sell pianos', 'question' => 'do you sell pianos', 'times_asked' => 3, 'first_asked_at' => now(), 'last_asked_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('password_reset_tokens')->insert(['email' => $customer->email, 'token' => 'x', 'created_at' => now()]);
        DB::table('failed_jobs')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'connection' => 'c', 'queue' => 'q', 'payload' => '{}', 'exception' => 'e', 'failed_at' => now()]);
        DB::table('extra_furniture_types')->update(['source_idea_id' => 5]);

        $files = [
            'refs'     => $this->makeFile('private/design-references/' . $customer->id . '/ref.png'),
            'idphoto'  => $this->makeFile('private/validations/' . $customer->id . '/id/a.jpg'),
            'service'  => $this->makeFile('private/service-photos/p.png'),
            'agree'    => $this->makeFile('private/agreements/r1-q1-x.jpg'),
            'ai'       => $this->makeFile('public/images/ai-designs/ai-design-x.jpg'),
            'product'  => $this->makeFile('public/images/products/abc.jpg'),
            'hero'     => $this->makeFile('public/images/hero/h.jpg'),
        ];

        return compact('admin', 'staff', 'customer', 'other', 'product', 'files');
    }

    private function snapshot(): array
    {
        return array_map(fn ($t) => DB::table($t)->count(), ['users', 'orders', 'payments', 'custom_design_requests', 'design_agreements', 'notifications', 'chatbot_logs', 'luma_unanswered_questions', 'unanswered_ideas', 'failed_jobs', 'products', 'design_ideas']);
    }

    /* ───────────── data:clear-test ───────────── */

    public function test_dry_run_changes_nothing_and_says_what_is_kept(): void
    {
        $s = $this->seedAll();
        $before = $this->snapshot();

        $this->artisan('data:clear-test')
            ->expectsOutputToContain('DRY RUN: nothing was changed')
            ->expectsOutputToContain('KEPT: database tables')
            ->expectsOutputToContain('KEPT: folders')
            ->assertSuccessful();

        $this->assertSame($before, $this->snapshot());
        foreach ($s['files'] as $f) {
            $this->assertFileExists($f);
        }
        $this->assertSame([], glob($this->tmp . '/logs/*'), 'a dry run writes no deleted-items list');
    }

    public function test_force_needs_the_word_clear(): void
    {
        $s = $this->seedAll();
        $before = $this->snapshot();

        $this->artisan('data:clear-test', ['--force' => true, '--confirm' => 'clear', '--backup-taken' => true])->assertFailed();
        $this->artisan('data:clear-test', ['--force' => true, '--backup-taken' => true])
            ->expectsQuestion('This deletes the data listed above from "' . DB::getDatabaseName() . '". Type CLEAR to continue', 'yes')
            ->assertFailed();

        $this->assertSame($before, $this->snapshot());
        $this->assertFileExists($s['files']['refs']);
    }

    public function test_force_removes_the_listed_data_and_keeps_the_rest(): void
    {
        $s = $this->seedAll();
        $keptIdeas = DB::table('design_ideas')->count();
        $keptPolicies = DB::table('agreement_policies')->count();
        $keptIntents = DB::table('chatbot_intents')->count();

        $this->artisan('data:clear-test', ['--force' => true, '--confirm' => 'CLEAR', '--backup-taken' => true])->assertSuccessful();

        foreach (['orders', 'payments', 'custom_design_requests', 'custom_quotes', 'design_agreements', 'notifications', 'chatbot_logs', 'luma_unanswered_questions', 'unanswered_ideas', 'password_reset_tokens', 'failed_jobs'] as $t) {
            $this->assertSame(0, DB::table($t)->count(), "{$t} should be empty");
        }
        // customers gone, admin and staff stay
        $this->assertNull(User::find($s['customer']->id));
        $this->assertNull(User::find($s['other']->id));
        $this->assertNotNull(User::find($s['admin']->id));
        $this->assertNotNull(User::find($s['staff']->id));
        $this->assertSame('admin', User::find($s['admin']->id)->role);

        // products, photos, quick ideas, policies, Luma's answers, extra types stay
        $this->assertSame(1, Product::count());
        $this->assertSame(1, ProductImage::count());
        $this->assertSame($keptIdeas, DB::table('design_ideas')->count());
        $this->assertSame($keptPolicies, DB::table('agreement_policies')->count());
        $this->assertSame($keptIntents, DB::table('chatbot_intents')->count());
        $this->assertGreaterThan(0, DB::table('extra_furniture_types')->count());
        $this->assertSame(0, DB::table('extra_furniture_types')->whereNotNull('source_idea_id')->count(), 'the link to a deleted idea is cleared');
        $this->assertGreaterThan(0, DB::table('business_settings')->count());

        // files: uploads gone, product and page art stay
        foreach (['refs', 'idphoto', 'service', 'agree', 'ai'] as $k) {
            $this->assertFileDoesNotExist($s['files'][$k]);
        }
        $this->assertFileExists($s['files']['product']);
        $this->assertFileExists($s['files']['hero']);

        // a deleted-items list was written
        $lists = glob($this->tmp . '/logs/data-clear-*.txt');
        $this->assertCount(1, $lists);
        $this->assertStringContainsString('ref.png', file_get_contents($lists[0]));
        $this->assertStringContainsString('DONE', file_get_contents($lists[0]));
    }

    public function test_production_is_refused_without_the_extra_flag(): void
    {
        $s = $this->seedAll();
        $before = $this->snapshot();
        $this->app['env'] = 'production';

        $this->artisan('data:clear-test', ['--force' => true, '--confirm' => 'CLEAR', '--backup-taken' => true])
            ->expectsOutputToContain('APP_ENV is production')
            ->assertFailed();
        $this->assertSame($before, $this->snapshot());
        $this->assertFileExists($s['files']['refs']);

        $this->artisan('data:clear-test', ['--force' => true, '--confirm' => 'CLEAR', '--backup-taken' => true, '--i-know-this-is-production' => true])->assertSuccessful();
        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_without_a_backup_nothing_is_deleted(): void
    {
        $s = $this->seedAll();
        $before = $this->snapshot();

        $this->artisan('data:clear-test', ['--force' => true, '--confirm' => 'CLEAR'])
            ->expectsOutputToContain('Take one manually first')
            ->assertFailed();

        $this->assertSame($before, $this->snapshot());
        $this->assertFileExists($s['files']['refs']);
    }

    public function test_a_failed_delete_rolls_everything_back(): void
    {
        $s = $this->seedAll();
        $before = $this->snapshot();

        // the last statement (deleting the customers) fails after everything before it already ran
        DB::beforeExecuting(function ($query) {
            if (str_starts_with($query, 'delete from `users`')) {
                throw new \RuntimeException('simulated failure');
            }
        });

        $this->artisan('data:clear-test', ['--force' => true, '--confirm' => 'CLEAR', '--backup-taken' => true])->assertFailed();

        $this->assertSame($before, $this->snapshot());
        $this->assertFileExists($s['files']['refs'], 'no file is deleted when the database part failed');
    }

    /* ───────────── storage:cleanup ───────────── */

    public function test_cleanup_dry_run_changes_nothing(): void
    {
        $orphan = $this->makeFile('private/service-photos/old.png', 'x', 48);

        $this->artisan('storage:cleanup', ['--notifications' => true, '--failed-jobs' => true, '--sessions' => true])
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        $this->assertFileExists($orphan);
        $this->assertFileDoesNotExist($this->tmp . '/state.json');
    }

    public function test_cleanup_removes_only_old_files_with_no_database_row(): void
    {
        $customer = $this->makeUser('customer');
        $request = $this->makeRequest($customer);
        $request->forceFill(['reference_image' => 'design-references/1/kept.png'])->save();
        DB::table('user_validations')->insert(['user_id' => $customer->id, 'attempt_number' => 1, 'id_photo_path' => 'validations/1/id/kept.jpg', 'selfie_photo_path' => 'validations/1/selfie/kept2.jpg', 'status' => 'pending', 'submitted_at' => now()]);

        $keptRef = $this->makeFile('private/design-references/1/kept.png', 'x', 100);
        $keptId = $this->makeFile('private/validations/1/id/kept.jpg', 'x', 100);
        $keptSelfie = $this->makeFile('private/validations/1/selfie/kept2.jpg', 'x', 100);
        $young = $this->makeFile('private/design-references/1/young.png', 'x', 2);          // no row, but only 2 h old
        $orphanA = $this->makeFile('private/design-references/1/orphan.png', 'x', 30);
        $orphanB = $this->makeFile('private/service-photos/orphan2.png', 'x', 30);
        $orphanC = $this->makeFile('public/images/ai-designs/ai-design-orphan.jpg', 'x', 30);
        $product = $this->makeFile('public/images/products/old-product.jpg', 'x', 5000);     // outside the listed folders
        $tmpOld = $this->makeFile('private/tmp/old.tmp', 'x', 30);
        $tmpNew = $this->makeFile('private/tmp/new.tmp', 'x', 1);
        $gitignore = $this->makeFile('public/images/ai-designs/.gitignore', '*', 5000);

        $this->artisan('storage:cleanup', ['--force' => true])->assertSuccessful();

        foreach ([$keptRef, $keptId, $keptSelfie, $young, $product, $tmpNew, $gitignore] as $f) {
            $this->assertFileExists($f);
        }
        foreach ([$orphanA, $orphanB, $orphanC, $tmpOld] as $f) {
            $this->assertFileDoesNotExist($f);
        }
        $this->assertFileExists($this->tmp . '/state.json');
    }

    public function test_cleanup_and_clear_never_touch_product_photos_or_the_old_backup(): void
    {
        $p = Product::create(['product_name' => 'Chair', 'category' => 'Chairs', 'price' => 1000, 'stock_quantity' => 1, 'product_status' => 'available']);
        ProductImage::create(['product_id' => $p->id, 'image_path' => 'product-photos/main.jpg', 'sort_order' => 1, 'is_primary' => true]);
        ProductImage::create(['product_id' => $p->id, 'image_path' => 'product-photos/extra.jpg', 'sort_order' => 2, 'is_primary' => false]);
        $p->update(['product_image' => 'product-photos/main.jpg']);

        $referenced = $this->makeFile('public_disk/product-photos/main.jpg', 'x', 5000);
        $extra = $this->makeFile('public_disk/product-photos/extra.jpg', 'x', 5000);
        $unreferenced = $this->makeFile('public_disk/product-photos/uploaded-just-now-by-admin.jpg', 'x', 5000);   // even without a row: never an "orphan"
        $backup = $this->makeFile('public/images/products/king-bed-frame.jpg', 'x', 5000);
        $orphan = $this->makeFile('private/service-photos/orphan.png', 'x', 30);

        $this->artisan('storage:cleanup', ['--force' => true])->assertSuccessful();
        $this->assertFileDoesNotExist($orphan);

        $this->artisan('data:clear-test', ['--force' => true, '--confirm' => 'CLEAR', '--backup-taken' => true])->assertSuccessful();

        foreach ([$referenced, $extra, $unreferenced, $backup] as $f) {
            $this->assertFileExists($f);
        }
        $this->assertSame(2, ProductImage::count());
        $this->assertSame('product-photos/main.jpg', $p->fresh()->product_image);
        $this->assertFalse(UploadFolders::delete($referenced), 'the delete helper refuses product photos outright');
        $this->assertFileExists($referenced);
    }

    public function test_cleanup_optional_items_only_when_asked(): void
    {
        $u = $this->makeUser('customer');
        DB::table('notifications')->insert([
            ['user_id' => $u->id, 'title' => 'a', 'message' => 'm', 'type' => 'general', 'is_read' => true, 'created_at' => now()->subDays(90)],
            ['user_id' => $u->id, 'title' => 'b', 'message' => 'm', 'type' => 'general', 'is_read' => false, 'created_at' => now()->subDays(90)],
            ['user_id' => $u->id, 'title' => 'c', 'message' => 'm', 'type' => 'general', 'is_read' => true, 'created_at' => now()->subDays(5)],
        ]);
        foreach ([20, 3] as $days) {
            DB::table('failed_jobs')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'connection' => 'c', 'queue' => 'q', 'payload' => '{}', 'exception' => 'e', 'failed_at' => now()->subDays($days)]);
        }

        $this->artisan('storage:cleanup', ['--force' => true])->assertSuccessful();
        $this->assertSame(3, DB::table('notifications')->count());
        $this->assertSame(2, DB::table('failed_jobs')->count());

        $this->artisan('storage:cleanup', ['--force' => true, '--notifications' => true, '--failed-jobs' => true])->assertSuccessful();
        $this->assertSame(['b', 'c'], DB::table('notifications')->orderBy('title')->pluck('title')->all(), 'only the old READ one goes');
        $this->assertSame(1, DB::table('failed_jobs')->count());
    }

    public function test_cleanup_is_scheduled_daily_at_3am_manila(): void
    {
        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command, 'storage:cleanup'));

        $this->assertNotNull($event);
        $this->assertSame('0 3 * * *', $event->expression);
        $this->assertSame('Asia/Manila', (string) $event->timezone);
        $this->assertStringContainsString('--force', $event->command);
        $this->assertStringNotContainsString('--notifications', $event->command, 'read notifications are not part of the scheduled run');
    }

    /* ───────────── uploads ───────────── */

    private function validateUpload(UploadedFile $file): bool
    {
        return Validator::make(['f' => $file], ['f' => UploadedImage::rules()])->passes();
    }

    public function test_only_real_jpeg_and_png_up_to_5_mb_pass(): void
    {
        $this->assertTrue($this->validateUpload(UploadedFile::fake()->createWithContent('a.png', base64_decode(self::PNG))));
        $this->assertTrue($this->validateUpload(UploadedFile::fake()->createWithContent('a.jpg', base64_decode(self::JPG))));
        // a PNG saved with a .jpg name is still a real PNG, the bytes decide
        $this->assertTrue($this->validateUpload(UploadedFile::fake()->createWithContent('a.jpg', base64_decode(self::PNG))));

        $this->assertFalse($this->validateUpload(UploadedFile::fake()->createWithContent('a.jpg', 'GIF89a' . str_repeat("\0", 40))));
        $this->assertFalse($this->validateUpload(UploadedFile::fake()->createWithContent('a.jpg', 'RIFF' . "\0\0\0\0" . 'WEBPVP8 ' . str_repeat("\0", 30))));
        $this->assertFalse($this->validateUpload(UploadedFile::fake()->createWithContent('a.png', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n")));
        $this->assertFalse($this->validateUpload(UploadedFile::fake()->createWithContent('a.jpg', '<?php echo 1;')));

        // over 5 MB
        $big = base64_decode(self::PNG) . str_repeat("\0", 5 * 1024 * 1024);
        $this->assertFalse($this->validateUpload(UploadedFile::fake()->createWithContent('big.png', $big)));
        $ok = base64_decode(self::PNG) . str_repeat("\0", 4 * 1024 * 1024);
        $this->assertTrue($this->validateUpload(UploadedFile::fake()->createWithContent('ok.png', $ok)));
    }

    public function test_admin_product_photos_refuse_webp_gif_and_oversize(): void
    {
        $admin = $this->makeUser('admin');
        $p = Product::create(['product_name' => 'Narra Table', 'category' => 'Tables', 'material' => 'Narra', 'finish' => 'Natural', 'price' => 5000, 'stock_quantity' => 2, 'product_status' => 'available']);
        $payload = ['product_name' => 'Narra Table', 'category' => 'Tables', 'material' => 'Narra', 'finish' => 'Natural', 'price' => '5000', 'stock_quantity' => '2', 'product_status' => 'available'];

        $bad = [
            UploadedFile::fake()->createWithContent('a.webp', base64_decode('UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==')),
            UploadedFile::fake()->createWithContent('a.jpg', 'GIF89a' . str_repeat("\0", 40)),
            UploadedFile::fake()->createWithContent('a.png', base64_decode(self::PNG) . str_repeat("\0", 5 * 1024 * 1024)),
        ];
        foreach ($bad as $file) {
            $this->actingAs($admin, 'admin')->put(route('admin.products.update', $p->id), $payload + ['extra_images' => [$file]])->assertSessionHasErrors('extra_images.0');
            $this->actingAs($admin, 'admin')->put(route('admin.products.update', $p->id), $payload + ['product_image' => $file])->assertSessionHasErrors('product_image');
        }
        $this->assertSame(0, ProductImage::where('product_id', $p->id)->count());
    }

    public function test_a_large_photo_is_shrunk_to_1600_px(): void
    {
        if (!UploadedImage::canResize()) {
            $this->markTestSkipped('The PHP gd extension is not loaded; resizing cannot run here.');
        }

        $img = imagecreatetruecolor(2400, 1200);
        $path = $this->tmp . '/big.jpg';
        imagejpeg($img, $path, 95);
        $file = new UploadedFile($path, 'big.jpg', 'image/jpeg', null, true);

        $this->assertTrue(UploadedImage::optimize($file));
        [$w, $h] = getimagesize($path);
        $this->assertSame(1600, $w);
        $this->assertSame(800, $h);

        // a small one is left alone
        $small = $this->tmp . '/small.jpg';
        imagejpeg(imagecreatetruecolor(300, 200), $small, 90);
        $before = md5_file($small);
        $this->assertFalse(UploadedImage::optimize(new UploadedFile($small, 'small.jpg', 'image/jpeg', null, true)));
        $this->assertSame($before, md5_file($small));
    }

    /* ───────────── System health ───────────── */

    public function test_system_health_page_is_admin_only_and_shows_the_numbers(): void
    {
        $this->makeFile('private/service-photos/orphan.png', str_repeat('x', 2048), 48);

        $this->get(route('admin.system-health.index'))->assertRedirect();
        $this->actingAs($this->makeUser('customer'))->get(route('admin.system-health.index'))->assertRedirect();

        $this->actingAs($this->makeUser('admin'), 'admin')->get(route('admin.system-health.index'))
            ->assertOk()
            ->assertSee('Disk used by uploads')
            ->assertSee('Orphan files')
            ->assertSee('Last storage cleanup')
            ->assertSee('Never')
            ->assertSee('Clear cache');
    }

    public function test_clear_cache_button_is_post_only_and_admin_only(): void
    {
        $this->post(route('admin.system-health.clear-cache'))->assertRedirect();
        $this->actingAs($this->makeUser('admin'), 'admin')->post(route('admin.system-health.clear-cache'))->assertSessionHas('success');
        $this->get(route('admin.system-health.clear-cache'))->assertStatus(405);
    }
}
