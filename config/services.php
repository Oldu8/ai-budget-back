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
        'webhook_url' => env('SLACK_WEBHOOK_URL'),
    ],

    'discord' => [
        /** Critical errors / Stripe issues — #error-logs */
        'errors_webhook_url' => env('DISCORD_ERRORS_WEBHOOK_URL', env('DISCORD_WEBHOOK_URL')),
        /** New AppUser signups — #new-users */
        'registrations_webhook_url' => env('DISCORD_REGISTRATIONS_WEBHOOK_URL'),
        /** Paid subscription started / canceled — #payment-info */
        'payments_webhook_url' => env('DISCORD_PAYMENTS_WEBHOOK_URL'),
        /** When true, registration messages include full email; otherwise only app_user_id + email domain */
        'registration_include_email' => filter_var(env('DISCORD_REGISTRATION_INCLUDE_EMAIL', false), FILTER_VALIDATE_BOOLEAN),
    ],

    'supabase' => [
        'url' => env('SUPABASE_URL'),
        'service_role' => env('SUPABASE_SERVICE_ROLE'),
        'storage_bucket' => env('SUPABASE_STORAGE_BUCKET', 'receipts'),
    ],

    /*
    | Own JWT auth (issued by Laravel). access = short-lived bearer; refresh = opaque DB token.
    */
    'jwt' => [
        'secret' => env('JWT_SECRET'),
        'ttl' => (int) env('JWT_TTL', 900),               // access token, seconds (default 15 min)
        'refresh_ttl' => (int) env('JWT_REFRESH_TTL', 2592000), // refresh token, seconds (default 30 days)
        'issuer' => env('JWT_ISSUER', env('APP_URL', 'ai-budget')),
    ],

    /*
    | Social sign-in (native flow: client sends id_token, server verifies via JWKS).
    | audiences = allowed `aud` client ids (iOS / Android / Web can differ → comma-separated).
    */
    'google' => [
        'audiences' => array_values(array_filter(array_map('trim', explode(',', (string) env('GOOGLE_CLIENT_IDS', ''))))),
    ],

    'apple' => [
        'audiences' => array_values(array_filter(array_map('trim', explode(',', (string) env('APPLE_CLIENT_IDS', ''))))),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'audio_model' => env('OPENAI_AUDIO_MODEL', 'whisper-1'),
    ],

    'stripe' => [
        'secret' => env('STRIPE_SECRET_KEY'),
        'public_key' => env('STRIPE_PUBLIC_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'success_url' => env('STRIPE_SUCCESS_URL')
            ?: rtrim((string) env('APP_URL', 'http://localhost'), '/').'/stripe/checkout/success',
        'cancel_url' => env('STRIPE_CANCEL_URL')
            ?: rtrim((string) env('APP_URL', 'http://localhost'), '/').'/stripe/checkout/cancel',
        'prices' => [
            'basic' => [
                'eur' => env('STRIPE_BASIC_PRICE_EUR'),
                'usd' => env('STRIPE_BASIC_PRICE_USD'),
            ],
            'plus' => [
                'eur' => env('STRIPE_PLUS_PRICE_EUR'),
                'usd' => env('STRIPE_PLUS_PRICE_USD'),
            ],
            'premium' => [
                'eur' => env('STRIPE_PREMIUM_PRICE_EUR'),
                'usd' => env('STRIPE_PREMIUM_PRICE_USD'),
            ],
        ],
    ],

    'api_secret' => env('API_SECRET', ''),

];
