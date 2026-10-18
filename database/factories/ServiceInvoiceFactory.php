<?php

namespace Database\Factories;

use App\Models\ServiceInquiry;
use App\Models\ServiceInvoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Test helper: a sent (issued) invoice with one line. Use ->draft(), ->paid(), ->cancelled() for other states. */
class ServiceInvoiceFactory extends Factory
{
    protected $model = ServiceInvoice::class;

    public function definition(): array
    {
        static $n = 0;
        $n++;

        return [
            'service_inquiry_id' => function (array $attrs) use ($n) {
                return ServiceInquiry::create([
                    'reference_number' => 'SI-2026-F' . str_pad((string) (random_int(1000, 9999) + $n), 5, '0', STR_PAD_LEFT),
                    'service_type' => 'repair', 'furniture_type' => 'Dining Table', 'concern' => 'Wobbly leg.',
                    'name' => 'Juan Dela Cruz', 'phone' => '09171234567', 'email' => 'juan@example.com',
                    'status' => 'new', 'user_id' => $attrs['user_id'] ?? null,
                ])->id;
            },
            'user_id'             => User::factory(),
            'invoice_number'      => 'INV-2026-' . str_pad((string) (9000 + $n), 4, '0', STR_PAD_LEFT),
            'status'              => ServiceInvoice::ISSUED,
            'customer_name'       => 'Juan Dela Cruz',
            'customer_phone'      => '09171234567',
            'customer_email'      => 'juan@example.com',
            'service_description' => 'Repair service: dining table',
            'delivery_option'     => 'none',
            'subtotal'            => '4000.00',
            'total'               => '4000.00',
            'issued_at'           => now(),
            'payment_plan'        => 'full',
            'amount_paid'         => '0.00',
        ];
    }

    public function halfHalf(): static
    {
        return $this->state(['payment_plan' => 'half_half']);
    }

    public function draft(): static
    {
        return $this->state(['status' => ServiceInvoice::DRAFT, 'invoice_number' => null, 'issued_at' => null]);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => ServiceInvoice::VOID, 'voided_at' => now(), 'void_reason' => 'Cancelled for a test.']);
    }

    public function configure(): static
    {
        return $this->afterCreating(function (ServiceInvoice $invoice) {
            if ($invoice->lines()->doesntExist()) {
                \App\Models\ServiceInvoiceLine::withoutEvents(fn () => \App\Models\ServiceInvoiceLine::create([
                    'invoice_id' => $invoice->id, 'kind' => 'service_cost', 'description' => $invoice->service_description, 'amount' => $invoice->total,
                ]));
            }
        });
    }
}
