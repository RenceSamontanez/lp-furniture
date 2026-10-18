<?php

namespace Database\Factories;

use App\Models\ServiceInvoice;
use App\Models\ServiceInvoicePayment;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServiceInvoicePaymentFactory extends Factory
{
    protected $model = ServiceInvoicePayment::class;

    public function definition(): array
    {
        return [
            'service_invoice_id' => ServiceInvoice::factory(),
            'installment'        => 1,
            'amount'             => '4000.00',
            'method'             => ServiceInvoicePayment::STRIPE,
            'status'             => ServiceInvoicePayment::PENDING,
            'provider_reference' => 'cs_test_' . $this->faker->unique()->bothify('????????????'),
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (array $a) => ['status' => ServiceInvoicePayment::PAID, 'paid_at' => now(),
            'paid_slot' => $a['service_invoice_id'] . '-' . $a['installment']]);
    }
}
