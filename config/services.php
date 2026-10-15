<?php

return [

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key'    => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel'              => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect'      => env('GOOGLE_REDIRECT_URI'),
    ],

    'stripe' => [
        'key'     => env('STRIPE_KEY'),
        'secret'  => env('STRIPE_SECRET'),
        'webhook' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    // Real GCash payments via PayMongo's Payment Intent API (test mode
    // — pk_test_/sk_test_ keys). No official-SDK dependency: PayMongo's
    // own PHP library is unversioned (v0.0.0) with a community fork
    // that exists specifically to fix its compatibility issues, so
    // this integration calls the REST API directly via Http, matching
    // this app's existing convention for other external APIs (recaptcha,
    // the AI design providers) rather than adding a fragile dependency.
    'paymongo' => [
        'public_key'     => env('PAYMONGO_PUBLIC_KEY'),
        'secret_key'     => env('PAYMONGO_SECRET_KEY'),
        'webhook_secret' => env('PAYMONGO_WEBHOOK_SECRET'),
    ],

    'openai' => [
        'api_key'      => env('OPENAI_API_KEY'),
        'organization' => env('OPENAI_ORGANIZATION'),
    ],

    'recaptcha' => [
        'site_key'   => env('RECAPTCHA_SITE_KEY'),
        'secret_key' => env('RECAPTCHA_SECRET_KEY'),
        'min_score'  => env('RECAPTCHA_MIN_SCORE', 0.5),
    ],


    'huggingface' => [
    'key' => env('HUGGINGFACE_API_KEY'),
],

'gemini' => [
    'key' => env('GEMINI_API_KEY'),
],
];