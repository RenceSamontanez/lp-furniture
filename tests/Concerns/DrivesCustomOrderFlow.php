<?php

namespace Tests\Concerns;

use App\Models\AiDesignSuggestion;
use App\Models\CustomDesignRequest;
use App\Models\CustomQuote;
use App\Models\DesignAgreement;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

/**
 * Drives the whole custom-order flow through the real HTTP routes with
 * faked gateways, for the Stage 11 end-to-end and edge-case suites.
 *
 * Only ever runs against the scratch database (tests/TestCase.php
 * aborts any other). The one image file it creates under
 * public/images/ai-designs has a phpunit- prefix and is removed in
 * tearDownFlow(); no existing file is read, changed or deleted.
 */
trait DrivesCustomOrderFlow
{
    use BuildsCustomOrderFixtures;

    protected const STRIPE_WEBHOOK_SECRET = 'whsec_test_fake_secret';

    protected string $flowImage = '';
    protected object $stripe;

    protected function setUpFlow(): void
    {
        Queue::fake();
        Storage::fake('local');
        Storage::fake('public');

        config([
            'services.stripe.secret'           => 'sk_test_fake',
            'services.stripe.webhook'          => self::STRIPE_WEBHOOK_SECRET,
            'services.paymongo.secret_key'     => 'sk_test_fake',
            'services.paymongo.webhook_secret' => 'whsec_paymongo_fake_secret',
        ]);

        $this->flowImage = 'images/ai-designs/phpunit-stage11-' . bin2hex(random_bytes(6)) . '.png';
        file_put_contents(public_path($this->flowImage), base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));

        $this->stripe = $this->fakeStripe();
    }

    protected function tearDownFlow(): void
    {
        ApiRequestor::setHttpClient(null);
        Carbon::setTestNow();

        if ($this->flowImage !== '' && is_file(public_path($this->flowImage))) {
            unlink(public_path($this->flowImage));
        }
    }

    /* ───────────── actors ───────────── */

    protected function flowAdmin(): User
    {
        $a = $this->makeUser('admin');
        $a->forceFill(['first_name' => 'Rhea', 'last_name' => 'Santos'])->save();

        return $a;
    }

    protected function flowCustomer(string $first = 'Ana'): User
    {
        $u = $this->makeUser();
        $u->forceFill(['first_name' => $first, 'last_name' => 'Cruz', 'phone_number' => '09170000001'])->save();

        return $u;
    }

    protected function asAdmin(User $admin)
    {
        return $this->actingAs($admin, 'admin');
    }

    protected function asCustomer(User $customer)
    {
        Auth::shouldUse('web');

        return $this->actingAs($customer);
    }

    /* ───────────── customer steps ───────────── */

    /** A generated design with one loadable concept, ready to be sent. */
    protected function generatedRequest(User $owner): array
    {
        $request = CustomDesignRequest::create([
            'user_id' => $owner->id, 'title' => 'Dining Table - ' . $owner->first_name,
            'furniture_type' => 'Dining Table', 'description' => 'A six-seater narra dining table',
            'status' => 'ai_generated',
        ]);
        $suggestion = AiDesignSuggestion::create([
            'design_id' => $request->id, 'image_path' => $this->flowImage, 'generated_at' => now(),
        ]);

        return [$request, $suggestion];
    }

    protected function submitRequest(User $customer, CustomDesignRequest $request, AiDesignSuggestion $s, array $extra = [])
    {
        return $this->asCustomer($customer)->post(route('design.sendToTeam', $request->id), array_merge([
            'suggestion_id' => $s->id, 'contact_phone' => '09171234567', 'contact_email' => 'ana@example.com',
            'preferred_call_window' => 'morning_8_12', 'request_mode' => 'exact',
        ], $extra));
    }

    /* ───────────── admin steps ───────────── */

    protected function adminPost(User $admin, string $route, CustomDesignRequest $r, array $data = [], ?int $version = null)
    {
        return $this->asAdmin($admin)
            ->from(route('admin.custom-orders.show', $r->id))
            ->post(route($route, $r->id), array_merge(['lock_version' => $version ?? $r->fresh()->lock_version], $data));
    }

    protected function quoteFields(array $over = []): array
    {
        return array_merge([
            'final_description' => 'Six-seater dining table in narra', 'width_cm' => '180', 'depth_cm' => '90',
            'height_cm' => '75', 'material' => 'Narra', 'finish' => 'Matte varnish', 'modifications' => 'Rounded corners',
            'price' => '20000', 'delivery_option' => 'delivery', 'delivery_fee' => '500',
            'delivery_address' => '12 Mabini St, Batangas City', 'production_days' => '20',
            'payment_terms' => 'deposit', 'deposit_amount' => '10250',
            'installment_duration_months' => '2', 'installment_frequency_days' => '30',
            'call_notes' => 'INTERNAL: customer wants it by December',
        ], $over);
    }

    /** Log an answered call, create the draft and send it. Returns the sent quote. */
    protected function quoteAndSend(User $admin, CustomDesignRequest $r, array $over = []): CustomQuote
    {
        $this->adminPost($admin, 'admin.custom-orders.calls', $r, ['answered' => '1', 'note' => 'Spoke to customer'])->assertSessionHasNoErrors();
        $this->adminPost($admin, 'admin.custom-orders.quotes.store', $r, $this->quoteFields($over))->assertSessionHasNoErrors();

        $draft = CustomQuote::where('request_id', $r->id)->where('status', 'draft')->firstOrFail();
        $this->asAdmin($admin)->post(
            route('admin.custom-orders.quotes.send', [$r->id, $draft->id]),
            ['lock_version' => $r->fresh()->lock_version]
        )->assertSessionHasNoErrors();

        return $draft->fresh();
    }

    protected function adminSetStatus(User $admin, Order $order, string $to, ?string $reason = null)
    {
        return $this->asAdmin($admin)->from(route('admin.orders.show', $order->id))
            ->post(route('admin.orders.updateStatus', $order->id), array_filter([
                'order_status' => $to, 'cancel_reason' => $reason,
            ], fn ($v) => $v !== null));
    }

    protected function recordCash(User $admin, Order $order, string $amount, ?int $installmentId = null, array $extra = [])
    {
        $this->asAdmin($admin)->get(route('admin.orders.show', $order->id))->assertOk();
        $tokens = session(\App\Http\Controllers\Admin\PaymentRecordController::TOKEN_SESSION_KEY);

        return $this->asAdmin($admin)->from(route('admin.orders.show', $order->id))
            ->post(route('admin.orders.payments.store', $order->id), array_merge([
                'form_token' => end($tokens), 'amount' => $amount, 'method' => 'cash',
                'paid_on' => now('Asia/Manila')->toDateString(),
                'installment_id' => $installmentId,
            ], $extra));
    }

    /* ───────────── gateways (fakes) ───────────── */

    protected function fakeStripe(): object
    {
        $client = new class implements ClientInterface {
            public array $posts = [];
            public array $sessions = [];
            public int $counter = 0;

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                if (strtoupper($method) === 'POST' && str_ends_with($absUrl, '/v1/checkout/sessions')) {
                    $this->counter++;
                    $id = 'cs_e2e_' . $this->counter;
                    $this->posts[] = $params;
                    $body = [
                        'object' => 'checkout.session', 'id' => $id, 'status' => 'open',
                        'url' => 'https://checkout.stripe.test/' . $id, 'payment_intent' => 'pi_e2e_' . $this->counter,
                        'amount_total' => $params['line_items'][0]['price_data']['unit_amount'] ?? 0,
                        'payment_status' => 'unpaid', 'metadata' => $params['metadata'] ?? [],
                    ];
                    $this->sessions[$id] = $body;

                    return [json_encode($body), 200, []];
                }

                foreach ($this->sessions as $id => $body) {
                    if (str_contains($absUrl, $id)) {
                        return [json_encode($body), 200, []];
                    }
                }

                return [json_encode(['error' => ['message' => 'No such session']]), 404, []];
            }
        };

        ApiRequestor::setHttpClient($client);

        return $client;
    }

    /** Stripe tells us (signed webhook) that the session was paid. */
    protected function stripePaid(Order $order, string $sessionId, string $eventId)
    {
        $session = $this->stripe->sessions[$sessionId];
        $session['payment_status'] = 'paid';
        $this->stripe->sessions[$sessionId] = $session;

        $json = json_encode(['id' => $eventId, 'object' => 'event', 'type' => 'checkout.session.completed', 'data' => ['object' => $session]]);
        $t = time();
        $sig = hash_hmac('sha256', "{$t}.{$json}", self::STRIPE_WEBHOOK_SECRET);

        return $this->call('POST', '/webhook/stripe', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$t},v1={$sig}", 'CONTENT_TYPE' => 'application/json',
        ], $json);
    }

    protected function agreementOf(CustomDesignRequest $r): DesignAgreement
    {
        return DesignAgreement::where('request_id', $r->id)->orderByDesc('id')->firstOrFail();
    }
}
