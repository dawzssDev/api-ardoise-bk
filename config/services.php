<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'currency' => env('STRIPE_CURRENCY', 'mxn'),
        // Días de prueba al crear suscripción (0 = sin trial). Ej: 14, 7, 3.
        'trial_days' => (int) env('STRIPE_TRIAL_DAYS', 7),
        // Price IDs de Stripe (Products → Prices)
        // Legacy (se tratan como Básico si siguen configurados)
        'price_prueba' => env('STRIPE_PRICE_PRUEBA'),
        'price_mensual' => env('STRIPE_PRICE_MENSUAL', env('STRIPE_PRICE_STANDARD_MENSUAL')),
        'price_anual' => env('STRIPE_PRICE_ANUAL', env('STRIPE_PRICE_STANDARD_ANUAL')),
        // Básico / Standard
        'price_basico_mensual' => env('STRIPE_PRICE_STANDARD_MENSUAL', 'price_1U9qQZQMCZvDbFTHnUAMRc4u'),
        'price_basico_anual' => env('STRIPE_PRICE_STANDARD_ANUAL', 'price_1U9qXhQMCZvDbFTHIKDk6Ban'),
        // Plus
        'price_plus_mensual' => env('STRIPE_PRICE_PLUS_MENSUAL', 'price_1UAz4LQMCZvDbFTHmXAXGI2m'),
        'price_plus_anual' => env('STRIPE_PRICE_PLUS_ANUAL', 'price_1UAz6uQMCZvDbFTH4w3cj16q'),
    ],

];
