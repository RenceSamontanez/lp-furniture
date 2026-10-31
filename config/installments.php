<?php

/*
 * Installments: every number the shop may want to change lives here.
 * Nothing else in the code base hard-codes a level, a cap, a point value or
 * a number of days.
 */
return [

    /** Bumped whenever the Installment Terms page changes in a way that needs a fresh acceptance. */
    'terms_version' => '2026-11-01',

    /*
     * Which driver takes the later installment charges: 'paymongo' (the real one) or 'sandbox' (simulated results, no
     * PayMongo call, only ever allowed with an sk_test_ key). Unset = sandbox while the PayMongo key is a test key,
     * paymongo otherwise. Going live = live keys + INSTALLMENT_GATEWAY=paymongo + the registered webhook.
     */
    'gateway' => env('INSTALLMENT_GATEWAY') ?: (str_starts_with((string) env('PAYMONGO_SECRET_KEY', ''), 'sk_test_') ? 'sandbox' : 'paymongo'),

    /**
     * Ask PayMongo to vault the card on the down payment (setup_future_usage). Off until PayMongo has enabled card
     * vaulting on the account: with it off the down payment is an ordinary card payment and cannot fail because of it.
     */
    'vault_cards' => (bool) env('INSTALLMENT_VAULT_CARDS', false),

    /** Down payment: exactly this share of the total is charged immediately. */
    'down_payment_percent' => 50,

    /** Installment Standing (0-100). Every new customer starts here. */
    'start_score' => 70,
    'min_score'   => 0,
    'max_score'   => 100,

    /*
     * Levels from the score: [min score, name, max months, max order total or null = no cap].
     * Highest "min" first. Suspended (0 months) means no installments at all.
     */
    'levels' => [
        ['min' => 85, 'name' => 'Excellent', 'months' => 3, 'cap' => null],
        ['min' => 60, 'name' => 'Good',      'months' => 3, 'cap' => 100000],
        ['min' => 40, 'name' => 'Fair',      'months' => 2, 'cap' => 30000],
        ['min' => 20, 'name' => 'Limited',   'months' => 1, 'cap' => 15000],
        ['min' => 0,  'name' => 'Suspended', 'months' => 0, 'cap' => 0],
    ],

    /** Points for each thing that can happen. */
    'points' => [
        'auto_debit_on_time'  => 4,
        'paid_early'          => 5,   // Pay Now before the due date
        'pay_now_on_due_date' => 4,   // Pay Now (GCash or card) on the due date, no failed charge before
        'paid_after_failure'  => -5,  // paid after a failed charge, inside the grace period
        'became_overdue'      => -15,
        'plan_finished_clean' => 10,  // plan finished with no Overdue installment
        'paid_when_overdue'   => 0,
    ],

    /** Daily job. */
    'schedule_time' => '08:00',
    'timezone'      => 'Asia/Manila',
    'reminder_days_before' => 3,
    /** A failed charge is retried this many days later (once); after the second failure a Pay Now link is sent. */
    'retry_after_days' => 1,
    /** Days after the due date before an unpaid installment becomes Overdue. */
    'grace_days' => 3,

    /** Allowed plan shapes. */
    'durations'   => [1, 2, 3],
    'frequencies' => [15, 30],

    /** Pay On-Site is not allowed for installments. Cards and GCash go through PayMongo only. */
    'methods' => ['paymongo_card', 'gcash'],

    /** Shop decisions printed on /installment-terms (the bracketed items of the sample terms). */
    'terms' => [
        'shop_name'        => env('SHOP_NAME', 'LP Furniture'),
        'contact_email'    => env('INSTALLMENT_CONTACT_EMAIL', env('MAIL_FROM_ADDRESS', 'hello@lpfurniture.example')),
        'contact_phone'    => env('INSTALLMENT_CONTACT_PHONE', '09XX XXX XXXX'),
        'contact_hours'    => 'Monday to Saturday, 9:00 AM to 5:00 PM',
        'stop_auto_debit_days' => 14,
        'refund_policy'    => 'Refunds are handled by the shop outside this website and follow the shop\'s cancellation policy.',
        'governing_law'    => 'the laws of the Republic of the Philippines',
    ],
];
