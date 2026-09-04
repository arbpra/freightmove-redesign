<?php

return [

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Resend
    |--------------------------------------------------------------------------
    |
    | The API key for outbound email. One value, no domain and no region to get
    | wrong — which is the reason for choosing it over the alternatives.
    |
    | The sending domain is verified in the Resend dashboard, and
    | MAIL_FROM_ADDRESS must sit on it or the send is refused.
    |
    | Blank is supported: mail falls back to whatever MAIL_MAILER says, and
    | `log` keeps the application working with nothing actually sent.
    |
    */

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | PayPal
    |--------------------------------------------------------------------------
    |
    | Checkout, Orders v2 — the integration the previous site used, so the
    | merchant account and the AUD pricing already exist.
    |
    | `mode` picks the API host: `sandbox` (default) or `live`. `webhook_id`
    | comes from the webhook you register in the PayPal dashboard and is what
    | makes signature verification possible; without it webhooks are refused
    | rather than trusted.
    |
    */

    'paypal' => [
        'client_id' => env('PAYPAL_CLIENT_ID'),
        'client_secret' => env('PAYPAL_CLIENT_SECRET'),
        'mode' => env('PAYPAL_MODE', 'sandbox'),
        'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
    ],

];
