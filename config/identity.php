<?php

/*
 * Identity validation settings. Change the accepted ID list here; the customer page reads it.
 */
return [
    'accepted_ids' => [
        'Passport',
        "Driver's license",
        'PhilSys National ID',
        'UMID',
        'PRC ID',
        'Postal ID',
    ],

    // The three things an admin must confirm before approving. Keys are stored in user_validations.checks_confirmed.
    'checks' => [
        'name' => 'Name matches the account',
        'face' => 'Face matches the selfie',
        'id'   => 'ID is valid and readable',
    ],

    'reason_chips' => [
        'Photo is blurry',
        'ID is expired',
        'Face is not visible',
        'Name does not match',
    ],

    'max_kb'         => 5120,
    'reason_max'     => 300,
];
