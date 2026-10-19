<?php

namespace Tests\Feature;

use App\Models\ServiceInquiry;
use App\Models\ServiceInvoice;
use App\Support\ServiceInquiryDesk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * Stage 21i (Prompt 8f, part 6): a ticket must say what the problem is (a clear description of 40 characters or a photo),
 * says where the work should be done, and the admin cannot send an invoice for a vague ticket without ticking
 * "I have enough details to do this work".
 */
class TicketQualityTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private function payload(array $over = []): array
    {
        return $over + [
            'service_type' => 'repair', 'furniture_type' => \App\Support\FurnitureTypes::LIST[0],
            'concern' => 'The right front leg of my dining table is cracked near the joint.',
            'name' => 'Juan Dela Cruz', 'phone' => '09171234567', 'email' => 'juan@example.com', 'terms' => '1',
        ];
    }

    /** A real 1x1 PNG: the test machine has no GD, so UploadedFile::fake()->image() is not available. */
    private function png(string $name = 'leg.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
    }

    private function customer()
    {
        $c = $this->makeUser();
        $this->app['auth']->forgetGuards();
        $this->actingAs($c);

        return $c;
    }

    private function vagueTicket(array $over = []): ServiceInquiry
    {
        return ServiceInquiry::create($over + [
            'reference_number' => 'SI-2026-' . random_int(10000, 99999), 'service_type' => 'repair', 'concern' => 'Needs work.',
            'name' => 'Juan', 'phone' => '09171234567', 'email' => 'juan@example.com', 'status' => 'new', 'user_id' => $this->makeUser()->id,
        ]);
    }

    public function test_the_migration_adds_two_nullable_columns_and_reverses(): void
    {
        $this->assertTrue(Schema::hasColumns('service_inquiries', ['work_location', 'reference_photos']));
        $migration = require database_path('migrations/2026_10_25_090000_add_work_location_and_photos_to_service_inquiries.php');
        $migration->up();   // idempotent
        $migration->down();
        $this->assertFalse(Schema::hasColumn('service_inquiries', 'work_location'));
        $this->assertFalse(Schema::hasColumn('service_inquiries', 'reference_photos'));
        $this->assertTrue(Schema::hasColumn('service_inquiries', 'concern'), 'only the two new columns are dropped');
        $migration->up();
        $this->assertTrue(Schema::hasColumns('service_inquiries', ['work_location', 'reference_photos']));
    }

    public function test_a_short_description_without_a_photo_is_refused_with_a_clear_message(): void
    {
        $this->customer();

        $r = $this->post('/services/request', $this->payload(['concern' => 'My table is broken a bit.']));   // 24 characters
        $r->assertSessionHasErrors(['concern' => ServiceInquiryDesk::DETAIL_MESSAGE]);
        $this->assertSame(0, ServiceInquiry::count());
    }

    public function test_forty_characters_or_a_photo_is_enough(): void
    {
        Storage::fake('local');
        $this->customer();

        $this->post('/services/request', $this->payload(['concern' => str_repeat('a', 40)]))->assertSessionHasNoErrors();
        $this->assertSame(1, ServiceInquiry::count());
        $this->assertTrue(ServiceInquiry::first()->hasEnoughDetail());

        $this->post('/services/request', $this->payload([
            'concern' => 'Leg is cracked.',
            'photos' => [$this->png()],
        ]))->assertSessionHasNoErrors();
        $photoTicket = ServiceInquiry::latest('id')->first();
        $this->assertCount(1, $photoTicket->reference_photos);
        Storage::disk('local')->assertExists($photoTicket->reference_photos[0]);
        $this->assertStringStartsWith('service-photos/', $photoTicket->reference_photos[0], 'private disk, never public');
        $this->assertTrue($photoTicket->hasEnoughDetail());
    }

    public function test_photo_rules(): void
    {
        Storage::fake('local');
        $this->customer();

        $this->post('/services/request', $this->payload(['photos' => [UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf')]]))->assertSessionHasErrors('photos.0');
        $this->post('/services/request', $this->payload(['photos' => [UploadedFile::fake()->create('big.png', 5000, 'image/png')]]))->assertSessionHasErrors('photos.0');
        $this->post('/services/request', $this->payload(['photos' => array_map(fn ($i) => $this->png("p{$i}.png"), [1, 2, 3, 4])]))->assertSessionHasErrors('photos');
        $this->assertSame(0, ServiceInquiry::count());
    }

    public function test_where_should_the_work_be_done_is_saved_validated_and_shown(): void
    {
        $this->customer();

        $this->post('/services/request', $this->payload(['work_location' => 'basement']))->assertSessionHasErrors('work_location');

        foreach (['home', 'shop', 'unsure'] as $place) {
            $this->post('/services/request', $this->payload(['work_location' => $place]))->assertSessionHasNoErrors();
            $this->assertSame($place, ServiceInquiry::latest('id')->first()->work_location);
        }
        $this->post('/services/request', $this->payload())->assertSessionHasNoErrors();
        $this->assertNull(ServiceInquiry::latest('id')->first()->work_location, 'optional: older tickets and desk tickets have none');

        $page = $this->get('/services/request');
        $page->assertSee('Where should the work be done?')->assertSee('At my home')->assertSee('At your shop')->assertSee('Not sure');
        $page->assertSee('what is wrong', false)->assertSee('since when', false)->assertSee('at least 40 characters', false);
        $page->assertSee('enctype="multipart/form-data"', false);
        $page->assertSee('Any visit or pickup fee is confirmed by our team');
    }

    public function test_the_admin_ticket_shows_the_place_the_photos_and_a_warning_for_a_vague_ticket(): void
    {
        Storage::fake('local');
        $admin = $this->makeUser('admin');
        $this->app['auth']->forgetGuards();
        $this->actingAs($admin, 'admin');

        $vague = $this->vagueTicket(['work_location' => 'home']);
        $page  = $this->get(route('admin.service-inquiries.show', $vague->id))->assertOk();
        $page->assertSee('Where the work is done')->assertSee('At my home')->assertSee('Very little detail on this ticket');

        Storage::disk('local')->put('service-photos/leg.jpg', 'x');
        $withPhoto = $this->vagueTicket(['reference_photos' => ['service-photos/leg.jpg']]);
        $page = $this->get(route('admin.service-inquiries.show', $withPhoto->id))->assertOk();
        $page->assertSee(route('admin.service-inquiries.photo', [$withPhoto->id, 0]), false)->assertDontSee('Very little detail on this ticket');
        $this->get(route('admin.service-inquiries.photo', [$withPhoto->id, 0]))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->get(route('admin.service-inquiries.photo', [$withPhoto->id, 5]))->assertNotFound();

        // a customer, or a guest, never gets a photo
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->makeUser(), 'web');
        $this->get(route('admin.service-inquiries.photo', [$withPhoto->id, 0]))->assertRedirect(route('admin.login'));
    }

    private function draftFor(ServiceInquiry $ticket, $admin): ServiceInvoice
    {
        $clean = \App\Support\ServiceInvoiceDesk::validate([
            'service_description' => 'Repair', 'service_cost' => '2000', 'delivery_option' => 'none',
            'customer_name' => 'Juan', 'customer_phone' => '09171234567', 'payment_plan' => 'full',
        ]);

        return app(\App\Support\ServiceInvoiceDesk::class)->createDraft($ticket->id, $admin, $clean);
    }

    public function test_sending_an_invoice_for_a_vague_ticket_needs_the_tick(): void
    {
        $admin = $this->makeUser('admin');
        $this->app['auth']->forgetGuards();
        $this->actingAs($admin, 'admin');

        $vague = $this->vagueTicket();
        $draft = $this->draftFor($vague, $admin);

        // the modal says so, with the shared notice, and has the required tick
        $this->get(route('admin.service-invoices.show', $draft->id))->assertOk()
            ->assertSee('This ticket has very little detail')->assertSee('lp-notice', false)
            ->assertSee('I have enough details to do this work')->assertSee('name="details_ok"', false);

        $this->post(route('admin.service-invoices.issue', $draft->id))->assertSessionHasErrors('invoice');
        $this->assertSame('draft', $draft->fresh()->status);

        $this->post(route('admin.service-invoices.issue', $draft->id), ['details_ok' => '1'])->assertSessionHas('success');
        $this->assertSame('issued', $draft->fresh()->status);
    }

    public function test_the_save_and_send_path_needs_the_tick_too(): void
    {
        $admin = $this->makeUser('admin');
        $this->app['auth']->forgetGuards();
        $this->actingAs($admin, 'admin');
        $draft = $this->draftFor($this->vagueTicket(), $admin);

        $payload = ['service_description' => 'Repair', 'service_cost' => '2000', 'delivery_option' => 'none', 'customer_name' => 'Juan', 'customer_phone' => '09171234567', 'payment_plan' => 'full', 'then' => 'send'];
        $this->post(route('admin.service-invoices.update', $draft->id), $payload)->assertSessionHasErrors('invoice');
        $this->assertSame('draft', $draft->fresh()->status);
        $this->post(route('admin.service-invoices.update', $draft->id), $payload + ['details_ok' => '1'])->assertSessionHas('success');
        $this->assertSame('issued', $draft->fresh()->status);
    }

    public function test_a_well_described_ticket_or_one_with_a_photo_sends_without_a_tick_or_a_notice(): void
    {
        $admin = $this->makeUser('admin');
        $this->app['auth']->forgetGuards();
        $this->actingAs($admin, 'admin');

        foreach ([['concern' => 'The right front leg of my dining table is cracked near the joint.'], ['reference_photos' => ['service-photos/a.jpg']]] as $over) {
            $draft = $this->draftFor($this->vagueTicket($over), $admin);
            $this->get(route('admin.service-invoices.show', $draft->id))->assertOk()
                ->assertDontSee('This ticket has very little detail')->assertDontSee('name="details_ok"', false);
            $this->post(route('admin.service-invoices.issue', $draft->id))->assertSessionHas('success');
        }
    }
}
