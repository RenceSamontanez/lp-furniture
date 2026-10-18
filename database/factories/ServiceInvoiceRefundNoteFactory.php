<?php

namespace Database\Factories;

use App\Models\ServiceInvoice;
use App\Models\ServiceInvoiceRefundNote;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServiceInvoiceRefundNoteFactory extends Factory
{
    protected $model = ServiceInvoiceRefundNote::class;

    public function definition(): array
    {
        return ['service_invoice_id' => ServiceInvoice::factory(), 'amount' => '1000.00', 'method' => 'cash', 'note' => 'Refund given at the shop.'];
    }
}
