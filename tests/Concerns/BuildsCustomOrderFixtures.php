<?php

namespace Tests\Concerns;

use App\Models\CustomDesignRequest;
use App\Models\CustomQuote;
use App\Models\DesignAgreement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;

/**
 * Small row builders for the custom-order tests. Everything they
 * create lives inside the test's rolled-back transaction.
 */
trait BuildsCustomOrderFixtures
{
    protected function makeUser(string $role = 'customer'): User
    {
        $user = new User();
        $user->forceFill([
            'first_name'     => ucfirst($role),
            'last_name'      => 'Tester',
            'email'          => $role . bin2hex(random_bytes(4)) . '@example.test',
            'password'       => 'not-a-real-password',
            'role'           => $role,
            'is_validated'   => true,
            'account_status' => 'active',
        ])->save();

        return $user;
    }

    protected function makeRequest(User $customer): CustomDesignRequest
    {
        return CustomDesignRequest::create([
            'user_id'        => $customer->id,
            'title'          => 'Test dining table',
            'furniture_type' => 'Dining Table',
            'description'    => 'A six-seater table',
            'status'         => 'approved',
        ]);
    }

    protected function makeQuote(CustomDesignRequest $request, array $overrides = []): CustomQuote
    {
        return CustomQuote::create(array_merge([
            'request_id'      => $request->id,
            'version'         => 1,
            'status'          => 'sent',
            'price'           => 20000,
            'delivery_option' => 'pickup',
            'delivery_fee'    => 0,
            'payment_terms'   => 'full',
        ], $overrides));
    }

    protected function makeAgreement(CustomQuote $quote, User $customer, User $admin, array $overrides = []): DesignAgreement
    {
        $snapshot = [
            'design'  => ['title' => 'Test dining table'],
            'price'   => '20000.00',
            'policy'  => ['version' => 1, 'text' => 'placeholder'],
            'version' => $quote->version,
        ];

        return DesignAgreement::create(array_merge([
            'agreement_number'  => 'AGR-TEST-' . bin2hex(random_bytes(4)),
            'request_id'        => $quote->request_id,
            'quote_id'          => $quote->id,
            'quote_version'     => $quote->version,
            'user_id'           => $customer->id,
            'finalized_by'      => $admin->id,
            'sent_at'           => now()->subHour(),
            'accepted_at'       => now(),
            'accepted_at_local' => now('Asia/Manila')->format('Y-m-d H:i:s T'),
            'ip_address'        => '127.0.0.1',
            'user_agent'        => 'PHPUnit',
            'policy_version'    => 1,
            'terms_snapshot'    => $snapshot,
            'snapshot_sha256'   => DesignAgreement::hashSnapshot($snapshot),
            'total_amount'      => 20000,
            'upfront_amount'    => 20000,
        ], $overrides));
    }

    protected function makeOrder(User $customer, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'user_id'         => $customer->id,
            'order_number'    => 'LP-T-' . bin2hex(random_bytes(5)),
            'total_amount'    => 20000,
            'delivery_option' => 'pickup',
            'delivery_fee'    => 0,
            'order_status'    => 'pending',
        ], $overrides));
    }

    protected function makePayment(Order $order, array $overrides = []): Payment
    {
        return Payment::create(array_merge([
            'order_id'       => $order->id,
            'user_id'        => $order->user_id,
            'amount'         => $order->total_amount,
            'payment_method' => 'stripe',
            'payment_type'   => 'full',
            'payment_status' => 'pending',
        ], $overrides));
    }
}
