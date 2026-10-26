<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use App\Models\UserValidation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/** Identity validation redesign: attempts, required checks, immutable decisions, follow-up, photo privacy, list. */
class IdentityValidationTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
    private const JPG = '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=';

    private array $allChecks = ['confirm_name' => 1, 'confirm_face' => 1, 'confirm_id' => 1];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
    }

    /* ───────────── helpers ───────────── */

    private function admin(): User
    {
        $a = $this->makeUser('admin');
        $a->forceFill(['first_name' => 'Rhea', 'last_name' => 'Santos'])->save();

        return $a;
    }

    private function customer(string $first = 'Ana', bool $validated = false): User
    {
        $u = $this->makeUser();
        $u->forceFill(['first_name' => $first, 'last_name' => 'Cruz', 'is_validated' => $validated])->save();

        return $u;
    }

    private function asAdmin(User $a)
    {
        Auth::shouldUse('admin');

        return $this->actingAs($a, 'admin');
    }

    private function asCustomer(User $u)
    {
        Auth::shouldUse('web');

        return $this->actingAs($u, 'web');
    }

    private function png(string $name = 'id.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::PNG));
    }

    private function jpg(string $name = 'selfie.jpg'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::JPG));
    }

    /** A stored submission with real photo files on the private disk. */
    private function submission(User $u, string $status = 'pending', int $attempt = 1, array $over = []): UserValidation
    {
        $id = "validations/{$u->id}/id/a{$attempt}.png";
        $selfie = "validations/{$u->id}/selfie/s{$attempt}.jpg";
        Storage::disk('private')->put($id, base64_decode(self::PNG));
        Storage::disk('private')->put($selfie, base64_decode(self::JPG));

        return UserValidation::create(array_merge([
            'user_id' => $u->id, 'attempt_number' => $attempt, 'id_photo_path' => $id, 'selfie_photo_path' => $selfie,
            'status' => $status, 'submitted_at' => now(),
        ], $status === 'rejected' ? ['rejection_reason' => 'Photo is blurry', 'reviewed_at' => now()] : [], $over));
    }

    private function rejected(User $u, int $attempt = 1): UserValidation
    {
        return $this->submission($u, 'rejected', $attempt);
    }

    private function submitAs(User $u, array $files = [], array $extra = [])
    {
        return $this->asCustomer($u)->post(route('validation.submit'), array_merge([
            'id_photo' => $this->png(), 'selfie_photo' => $this->jpg(), 'confirm' => '1',
        ], $files, $extra));
    }

    /* ───────────── required checks ───────────── */

    public function test_approve_without_all_three_checks_is_rejected_by_the_server(): void
    {
        $admin = $this->admin();
        $v = $this->submission($this->customer());

        foreach ([[], ['confirm_name' => 1], ['confirm_name' => 1, 'confirm_face' => 1], ['confirm_name' => 1, 'confirm_face' => 1, 'confirm_id' => 0]] as $partial) {
            $this->asAdmin($admin)->post(route('admin.validations.approve', $v->id), $partial)
                ->assertRedirect(route('admin.validations.show', $v->id))
                ->assertSessionHas('error', 'Tick all three checks before approving.');
        }

        $v->refresh();
        $this->assertSame('pending', $v->status);
        $this->assertFalse((bool) $v->user->fresh()->is_validated);
        $this->assertNull($v->checks_confirmed);
        $this->assertSame(0, Notification::where('user_id', $v->user_id)->count());
    }

    public function test_approve_with_all_three_checks_stores_them_and_notifies(): void
    {
        $admin = $this->admin();
        $v = $this->submission($this->customer());

        $this->asAdmin($admin)->post(route('admin.validations.approve', $v->id), $this->allChecks)
            ->assertRedirect(route('admin.validations.show', $v->id))->assertSessionHas('success');

        $v->refresh();
        $this->assertSame('approved', $v->status);
        $this->assertSame(['name' => true, 'face' => true, 'id' => true], $v->checks_confirmed);
        $this->assertSame($admin->id, $v->reviewed_by);
        $this->assertNotNull($v->reviewed_at);
        $this->assertTrue((bool) $v->user->fresh()->is_validated);
        $this->assertSame(1, Notification::where('user_id', $v->user_id)->where('type', 'validation_approved')->count());
    }

    /* ───────────── immutable decisions ───────────── */

    public function test_a_decided_submission_cannot_be_approved_or_rejected_again(): void
    {
        $admin = $this->admin();
        $approved = $this->submission($this->customer('A'), 'pending');
        $this->asAdmin($admin)->post(route('admin.validations.approve', $approved->id), $this->allChecks);
        $rejected = $this->rejected($this->customer('B'));

        // approved -> reject / approve again
        $this->asAdmin($admin)->post(route('admin.validations.reject', $approved->id), ['rejection_reason' => 'Changed my mind'])
            ->assertSessionHas('error');
        $this->asAdmin($admin)->post(route('admin.validations.approve', $approved->id), $this->allChecks)->assertSessionHas('error');
        // rejected -> approve / reject again
        $this->asAdmin($admin)->post(route('admin.validations.approve', $rejected->id), $this->allChecks)->assertSessionHas('error');
        $this->asAdmin($admin)->post(route('admin.validations.reject', $rejected->id), ['rejection_reason' => 'Another reason'])->assertSessionHas('error');

        $this->assertSame('approved', $approved->fresh()->status);
        $this->assertNull($approved->fresh()->rejection_reason);
        $this->assertSame('rejected', $rejected->fresh()->status);
        $this->assertSame('Photo is blurry', $rejected->fresh()->rejection_reason);
        $this->assertFalse((bool) $rejected->user->fresh()->is_validated);
    }

    public function test_a_decided_submission_answers_409_to_a_json_caller(): void
    {
        $admin = $this->admin();
        $v = $this->rejected($this->customer());

        $this->asAdmin($admin)->postJson(route('admin.validations.approve', $v->id), $this->allChecks)->assertStatus(409);
        $this->asAdmin($admin)->postJson(route('admin.validations.reject', $v->id), ['rejection_reason' => 'x y'])->assertStatus(409);
    }

    /* ───────────── reject reason ───────────── */

    public function test_reject_needs_a_reason_without_a_link_or_email_and_within_300_characters(): void
    {
        $admin = $this->admin();
        $v = $this->submission($this->customer());
        $url = route('admin.validations.reject', $v->id);

        $this->asAdmin($admin)->post($url, ['rejection_reason' => ''])->assertSessionHasErrors('rejection_reason');
        $this->asAdmin($admin)->post($url, ['rejection_reason' => '   '])->assertSessionHasErrors('rejection_reason');
        $this->asAdmin($admin)->post($url, ['rejection_reason' => 'See www.example.com for tips'])->assertSessionHasErrors('rejection_reason');
        $this->asAdmin($admin)->post($url, ['rejection_reason' => 'Send it to help@example.com'])->assertSessionHasErrors('rejection_reason');
        $this->asAdmin($admin)->post($url, ['rejection_reason' => str_repeat('a', 301)])->assertSessionHasErrors('rejection_reason');
        $this->assertSame('pending', $v->fresh()->status);

        $this->asAdmin($admin)->post($url, ['rejection_reason' => 'Photo is <b>blurry</b>'])->assertSessionHasNoErrors();
        $v->refresh();
        $this->assertSame('rejected', $v->status);
        $this->assertSame('Photo is blurry', $v->rejection_reason);
        $this->assertSame(1, Notification::where('user_id', $v->user_id)->where('type', 'validation_rejected')->count());
    }

    /* ───────────── follow-up ───────────── */

    public function test_follow_up_works_only_on_the_latest_rejected_attempt_and_notifies_the_customer(): void
    {
        $admin = $this->admin();
        $u = $this->customer();
        $first = $this->rejected($u, 1);
        $second = $this->rejected($u, 2);

        // an older attempt: refused
        $this->asAdmin($admin)->post(route('admin.validations.follow-up', $first->id))->assertSessionHas('error');
        $this->assertNull($first->fresh()->follow_up_requested_at);

        // the latest rejected: sent, with the reason and a link to the submission page
        $this->asAdmin($admin)->post(route('admin.validations.follow-up', $second->id))->assertSessionHas('success');
        $second->refresh();
        $this->assertNotNull($second->follow_up_requested_at);
        $this->assertSame($admin->id, $second->follow_up_requested_by);
        $n = Notification::where('user_id', $u->id)->latest('id')->first();
        $this->assertStringContainsString('Photo is blurry', $n->message);
        $this->assertSame(route('validation.show'), $n->url);

        // repeating it sends nothing more
        $this->asAdmin($admin)->post(route('admin.validations.follow-up', $second->id))->assertSessionHas('info');
        $this->assertSame(1, Notification::where('user_id', $u->id)->count());

        // the page now says it is waiting for the customer
        $this->asAdmin($admin)->get(route('admin.validations.show', $second->id))
            ->assertSee('Waiting for the customer to send new photos')->assertDontSee('Ask for a new submission');
    }

    public function test_follow_up_is_refused_on_pending_and_approved_submissions(): void
    {
        $admin = $this->admin();
        $p = $this->submission($this->customer('P'));
        $a = $this->submission($this->customer('Q'), 'approved');

        $this->asAdmin($admin)->post(route('admin.validations.follow-up', $p->id))->assertSessionHas('error');
        $this->asAdmin($admin)->post(route('admin.validations.follow-up', $a->id))->assertSessionHas('error');
        $this->assertSame(0, Notification::count());
    }

    /* ───────────── attempts ───────────── */

    public function test_resubmitting_after_a_rejection_creates_attempt_two_and_keeps_attempt_one(): void
    {
        $u = $this->customer();
        $first = $this->rejected($u);

        $this->submitAs($u)->assertRedirect(route('validation.show'))->assertSessionHasNoErrors();

        $this->assertSame(2, UserValidation::where('user_id', $u->id)->count());
        $this->assertSame('rejected', $first->fresh()->status);
        $this->assertSame('Photo is blurry', $first->fresh()->rejection_reason);
        $second = UserValidation::where('user_id', $u->id)->where('attempt_number', 2)->first();
        $this->assertSame('pending', $second->status);
        $this->assertNotSame($first->id_photo_path, $second->id_photo_path);
        $this->assertTrue(Storage::disk('private')->exists($first->id_photo_path));
        $this->assertTrue(Storage::disk('private')->exists($second->id_photo_path));
        $this->assertSame($second->id, $u->fresh()->validation->id, 'the user relation points at the latest attempt');
    }

    public function test_the_first_submission_is_attempt_one_and_existing_rows_default_to_attempt_one(): void
    {
        $u = $this->customer();
        $this->submitAs($u)->assertSessionHasNoErrors();
        $this->assertSame(1, UserValidation::where('user_id', $u->id)->value('attempt_number'));

        $old = UserValidation::create(['user_id' => $this->customer('Old')->id, 'id_photo_path' => 'x', 'selfie_photo_path' => 'y', 'status' => 'approved']);
        $this->assertSame(1, (int) $old->fresh()->attempt_number);
    }

    public function test_no_submit_while_pending(): void
    {
        $u = $this->customer();
        $this->submission($u);

        $this->submitAs($u)->assertRedirect(route('validation.show'))->assertSessionHas('error');
        $this->assertSame(1, UserValidation::where('user_id', $u->id)->count());
        $this->assertCount(2, Storage::disk('private')->allFiles(), 'no new file was left behind');
    }

    public function test_no_submit_after_approval(): void
    {
        $u = $this->customer('Done', true);
        $this->submission($u, 'approved');

        $this->submitAs($u)->assertRedirect(route('validation.show'))->assertSessionHas('error');
        $this->assertSame(1, UserValidation::where('user_id', $u->id)->count());
    }

    /* ───────────── uploads ───────────── */

    public static function badFiles(): array
    {
        return [
            'gif'      => ['a.gif', 'GIF89a' . str_repeat("\0", 40)],
            'webp'     => ['a.webp', 'RIFF' . "\0\0\0\0" . 'WEBPVP8 ' . str_repeat("\0", 30)],
            'pdf'      => ['a.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n"],
            'svg'      => ['a.svg', '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>'],
            'mp4'      => ['a.mp4', "\0\0\0\x18ftypmp42" . str_repeat("\0", 40)],
            'php file named jpg' => ['photo.jpg', '<?php echo "pwned";'],
            'svg named png'      => ['photo.png', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'],
        ];
    }

    #[DataProvider('badFiles')]
    public function test_wrong_file_types_are_rejected_in_either_slot(string $name, string $bytes): void
    {
        $u = $this->customer();
        $bad = UploadedFile::fake()->createWithContent($name, $bytes);

        $this->submitAs($u, ['id_photo' => $bad])->assertSessionHasErrors('id_photo');
        $this->submitAs($u, ['selfie_photo' => $bad])->assertSessionHasErrors('selfie_photo');
        $this->assertSame(0, UserValidation::count());
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_a_file_over_5_mb_is_rejected(): void
    {
        $u = $this->customer();

        $this->submitAs($u, ['id_photo' => $this->png()->size(5121)])->assertSessionHasErrors(['id_photo' => 'The ID photo is over 5 MB. Choose a smaller one.']);
        $this->submitAs($u, ['selfie_photo' => $this->jpg()->size(6000)])->assertSessionHasErrors('selfie_photo');
        $this->assertSame(0, UserValidation::count());

        $this->submitAs($u, ['id_photo' => $this->png()->size(5120)])->assertSessionHasNoErrors();
        $this->assertSame(1, UserValidation::count());
    }

    public function test_both_photos_and_the_confirmation_are_required(): void
    {
        $u = $this->customer();

        $this->asCustomer($u)->post(route('validation.submit'), ['confirm' => '1'])->assertSessionHasErrors(['id_photo', 'selfie_photo']);
        $this->submitAs($u, [], ['confirm' => null])->assertSessionHasErrors('confirm');
    }

    public function test_new_photos_go_to_the_private_disk_only(): void
    {
        Storage::fake('public');
        $u = $this->customer();
        $this->submitAs($u)->assertSessionHasNoErrors();

        $v = UserValidation::first();
        $this->assertTrue(Storage::disk('private')->exists($v->id_photo_path));
        $this->assertTrue(Storage::disk('private')->exists($v->selfie_photo_path));
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    /* ───────────── photo privacy ───────────── */

    public function test_admin_photo_route_sends_a_no_store_image_to_admins_only(): void
    {
        $v = $this->submission($this->customer());
        $url = route('admin.validations.document', ['id' => $v->id, 'type' => 'id']);

        $res = $this->asAdmin($this->admin())->get($url)->assertOk();
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
        $this->assertSame('image/png', $res->headers->get('Content-Type'));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->asAdmin($this->admin())->get(route('admin.validations.document', ['id' => $v->id, 'type' => 'selfie']))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_guests_and_customers_cannot_use_the_admin_photo_route(): void
    {
        $owner = $this->customer();
        $v = $this->submission($owner);
        $url = route('admin.validations.document', ['id' => $v->id, 'type' => 'id']);

        $this->assertContains($this->get($url)->getStatusCode(), [302, 403, 404]);
        $this->assertNotSame(200, $this->asCustomer($owner)->get($url)->getStatusCode());
        $this->assertNotSame(200, $this->asCustomer($this->customer('Other'))->get($url)->getStatusCode());
    }

    public function test_an_owner_sees_only_their_own_photos(): void
    {
        $owner = $this->customer();
        $other = $this->customer('Other');
        $v = $this->submission($owner);
        $url = route('validation.photo', ['id' => $v->id, 'type' => 'id']);

        $res = $this->asCustomer($owner)->get($url)->assertOk();
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
        $this->asCustomer($other)->get($url)->assertNotFound();
        Auth::guard('web')->logout();
        $this->get($url)->assertRedirect();
        $this->asAdmin($this->admin())->get($url)->assertNotFound(); // an admin has no customer session here
    }

    public function test_a_stored_file_that_is_not_a_real_jpeg_or_png_is_never_sent(): void
    {
        $v = $this->submission($this->customer());
        Storage::disk('private')->put($v->id_photo_path, '<?php echo 1;');

        $this->asAdmin($this->admin())->get(route('admin.validations.document', ['id' => $v->id, 'type' => 'id']))->assertNotFound();
        $this->asCustomer($v->user)->get(route('validation.photo', ['id' => $v->id, 'type' => 'id']))->assertNotFound();
    }

    public function test_there_is_no_public_url_for_identity_photos(): void
    {
        $v = $this->submission($this->customer());

        $this->assertNotSame(200, $this->get('/storage/' . $v->id_photo_path)->getStatusCode());
        $this->assertNotSame(200, $this->get('/' . $v->id_photo_path)->getStatusCode());
        $this->assertNull(config('filesystems.disks.private.url'), 'the private disk has no public URL');
        $this->assertFileDoesNotExist(public_path($v->id_photo_path));
    }

    /* ───────────── admin list ───────────── */

    public function test_list_tabs_counts_and_search(): void
    {
        $admin = $this->admin();
        $this->submission($this->customer('Alice'));
        $this->submission($this->customer('Alma'));
        $this->submission($this->customer('Bianca'), 'approved');
        $this->rejected($this->customer('Carla'));
        $this->rejected($this->customer('Carlo'));
        $this->rejected($this->customer('Cora'));

        $page = $this->asAdmin($admin)->get(route('admin.validations.index'))->assertOk();
        $page->assertSeeInOrder(['Pending', '2', 'Approved', '1', 'Rejected', '3']);
        $page->assertSee('Alice Cruz')->assertSee('Alma Cruz')->assertDontSee('Bianca Cruz')->assertDontSee('Carla Cruz');
        $page->assertSee('Review');

        $this->asAdmin($admin)->get(route('admin.validations.index', ['status' => 'approved']))->assertSee('Bianca Cruz')->assertSee('View')->assertDontSee('Alice Cruz');
        $this->asAdmin($admin)->get(route('admin.validations.index', ['status' => 'rejected']))->assertSee('Carla Cruz')->assertSee('Cora Cruz');

        // search by name and by email, inside the chosen tab
        $this->asAdmin($admin)->get(route('admin.validations.index', ['status' => 'rejected', 'q' => 'carl']))
            ->assertSee('Carla Cruz')->assertSee('Carlo Cruz')->assertDontSee('Cora Cruz');
        $email = User::where('first_name', 'Cora')->value('email');
        $this->asAdmin($admin)->get(route('admin.validations.index', ['status' => 'rejected', 'q' => $email]))
            ->assertSee('Cora Cruz')->assertDontSee('Carla Cruz');
        $this->asAdmin($admin)->get(route('admin.validations.index', ['q' => 'zzzz-nobody']))->assertSee('Nothing here');
        // wildcard characters in the search are plain text
        $this->asAdmin($admin)->get(route('admin.validations.index', ['q' => '%']))->assertSee('Nothing here');
        // an unknown tab falls back to Pending
        $this->asAdmin($admin)->get(route('admin.validations.index', ['status' => 'bogus']))->assertSee('Alice Cruz');
    }

    public function test_list_shows_the_attempt_number(): void
    {
        $u = $this->customer('Dana');
        $this->rejected($u, 1);
        $this->submission($u, 'pending', 2);

        $this->asAdmin($this->admin())->get(route('admin.validations.index'))->assertSee('Attempt 2');
    }

    public function test_admin_pages_are_closed_to_customers_and_guests(): void
    {
        $v = $this->submission($this->customer());

        foreach ([route('admin.validations.index'), route('admin.validations.show', $v->id)] as $url) {
            $this->get($url)->assertRedirect();
            $this->asCustomer($this->customer('Nosy'))->get($url)->assertRedirect();
        }
        $this->post(route('admin.validations.approve', $v->id), $this->allChecks)->assertRedirect();
        $this->assertSame('pending', $v->fresh()->status);
    }

    /* ───────────── review page states ───────────── */

    public function test_review_page_shows_a_pending_submission_with_checks_and_attempt(): void
    {
        $u = $this->customer();
        $this->rejected($u, 1);
        $v = $this->submission($u, 'pending', 2);

        $this->asAdmin($this->admin())->get(route('admin.validations.show', $v->id))->assertOk()
            ->assertSee('Attempt 2')->assertSee('Past attempts')->assertSee('Name matches the account')
            ->assertSee('Face matches the selfie')->assertSee('ID is valid and readable')->assertSee('0 of 3')
            ->assertSee('Photo is blurry')->assertSee('Approve verification');
    }

    public function test_decided_review_pages_are_read_only_and_say_so(): void
    {
        $admin = $this->admin();
        $a = $this->submission($this->customer('A'));
        $this->asAdmin($admin)->post(route('admin.validations.approve', $a->id), $this->allChecks);
        $r = $this->rejected($this->customer('R'));
        $r->forceFill(['reviewed_by' => $admin->id])->save();

        $this->asAdmin($admin)->get(route('admin.validations.show', $a->id))
            ->assertSee('Approved by Rhea Santos')->assertSee('This decision is final.')->assertSee('Name matches the account')
            ->assertDontSee('Approve verification')->assertDontSee('Reason, needed only to reject');

        $this->asAdmin($admin)->get(route('admin.validations.show', $r->id))
            ->assertSee('Rejected by Rhea Santos')->assertSee('This decision is final.')->assertSee('Reason sent to the customer: Photo is blurry')
            ->assertSee('Ask for a new submission')->assertDontSee('Approve verification');
    }

    public function test_the_follow_up_button_is_hidden_on_an_older_rejected_attempt(): void
    {
        $u = $this->customer();
        $first = $this->rejected($u, 1);
        $this->submission($u, 'pending', 2);

        $this->asAdmin($this->admin())->get(route('admin.validations.show', $first->id))
            ->assertDontSee('Ask for a new submission')->assertSee('newer submission');
    }

    /* ───────────── customer page ───────────── */

    public function test_customer_page_not_submitted_shows_the_form_and_the_guidance(): void
    {
        $page = $this->asCustomer($this->customer())->get(route('validation.show'))->assertOk();

        $page->assertSee('Verify your identity')->assertSee('What we check')->assertSee('IDs we accept')
            ->assertSee('name="id_photo"', false)->assertSee('name="selfie_photo"', false)
            ->assertSee('JPEG or PNG, up to 5 MB')
            ->assertSee('Only LP Furniture admins can see your photos. We use them to verify you and for nothing else.');
        foreach (config('identity.accepted_ids') as $id) {
            $page->assertSee(e($id), false);
        }
        $this->assertCount(6, config('identity.accepted_ids'));
        $page->assertDontSee('AI design');
    }

    public function test_customer_page_pending_state_has_no_form(): void
    {
        $u = $this->customer();
        $v = $this->submission($u);

        $this->asCustomer($u)->get(route('validation.show'))->assertOk()
            ->assertSee('We are reviewing your photos')->assertDontSee('name="id_photo"', false)
            ->assertSee(route('validation.photo', ['id' => $v->id, 'type' => 'id']), false);
    }

    public function test_customer_page_approved_state(): void
    {
        $u = $this->customer('Ok', true);
        $this->submission($u, 'approved');

        $this->asCustomer($u)->get(route('validation.show'))->assertOk()
            ->assertSee('Identity verified')->assertDontSee('name="id_photo"', false);
    }

    public function test_customer_page_rejected_state_shows_the_reason_and_a_new_form(): void
    {
        $u = $this->customer();
        $this->rejected($u);

        $this->asCustomer($u)->get(route('validation.show'))->assertOk()
            ->assertSee('Your last submission was rejected')->assertSee('Reason: Photo is blurry')
            ->assertSee('name="id_photo"', false)->assertSee('This will be attempt 2.');
    }

    public function test_the_admin_badge_counts_pending_attempts(): void
    {
        $u = $this->customer();
        $this->rejected($u, 1);
        $this->submission($u, 'pending', 2);
        $this->submission($this->customer('B'), 'pending');
        $this->submission($this->customer('C'), 'approved');

        $this->assertSame(2, UserValidation::where('status', 'pending')->count());
        $this->asAdmin($this->admin())->get(route('admin.dashboard'))->assertOk();
    }
}
