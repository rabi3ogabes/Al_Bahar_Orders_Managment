<?php

/*
|--------------------------------------------------------------------------
| Platform configuration — Al Bahar Home & Living
|--------------------------------------------------------------------------
| Static, deploy-time configuration. Values that operations staff must be
| able to change without a deployment live in the `system_settings` table
| and are read through App\Domain\Settings\Services\Settings, which falls
| back to the defaults declared here.
*/

return [

    'brand' => [
        'name_en' => env('APP_NAME', 'Al Bahar Home & Living'),
        'name_ar' => env('APP_NAME_AR', 'البحر للمنزل والمعيشة'),
    ],

    'business_timezone' => env('APP_BUSINESS_TIMEZONE', 'Asia/Qatar'),
    'currency' => env('APP_CURRENCY', 'QAR'),
    'country' => 'QA',
    'supported_locales' => explode(',', env('APP_SUPPORTED_LOCALES', 'en,ar')),
    'rtl_locales' => ['ar'],

    'phone' => [
        // Country calling codes accepted for customer registration.
        'allowed_country_codes' => array_map('trim', explode(',', env('PHONE_ALLOWED_COUNTRY_CODES', '974'))),
        'default_country_code' => '974',
    ],

    'auth' => [
        'access_token_ttl_minutes' => (int) env('AUTH_ACCESS_TOKEN_TTL_MINUTES', 60),
        'refresh_token_ttl_days' => (int) env('AUTH_REFRESH_TOKEN_TTL_DAYS', 60),
        'password_login_enabled' => true,
        'max_sessions_per_user' => 10,
    ],

    'otp' => [
        'length' => 6,
        'ttl_seconds' => (int) env('OTP_TTL_SECONDS', 300),
        'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),
        'resend_cooldown_seconds' => (int) env('OTP_RESEND_COOLDOWN_SECONDS', 60),
        'max_per_phone_per_hour' => 5,
        // Development convenience only. Hard-disabled outside local/testing in AppServiceProvider.
        'dev_echo' => (bool) env('OTP_DEV_ECHO', false),
    ],

    'checkout' => [
        'reservation_ttl_minutes' => 30,         // stock held for unpaid online payments
        'cod_reservation_ttl_minutes' => null,   // COD/pay-at-pickup holds until fulfilled/cancelled
        'cart_ttl_days' => 30,
        'max_quantity_per_line' => 50,
        'service_fee' => '0.00',
        'cod_fee' => '10.00',
        'tax_rate_percent' => '0',               // Qatar: no VAT at time of writing; configurable.
        'allocation' => [
            'prefer_selected_branch' => true,
            'prefer_fewest_branches' => true,
            'prefer_nearest' => true,
            'allow_split' => true,
        ],
    ],

    'pickup' => [
        'token_ttl_hours' => 72,
        'alternate_collector_enabled' => true,
    ],

    'payments' => [
        'online_payment_window_minutes' => 30,
        'sadad' => [
            'enabled' => (bool) env('SADAD_ENABLED', false),
            'mode' => env('SADAD_MODE', 'sandbox'),
            'merchant_id' => env('SADAD_MERCHANT_ID'),
            'secret_key' => env('SADAD_SECRET_KEY'),
            'website' => env('SADAD_WEBSITE'),
            'checkout_url' => env('SADAD_CHECKOUT_URL'),
            'status_url' => env('SADAD_STATUS_URL'),
            // Capabilities must be confirmed against the merchant agreement. See docs/integrations/SADAD.md.
            'capabilities' => [
                'hosted_checkout' => true,
                'status_api' => (bool) env('SADAD_STATUS_URL', false),
                'refund_api' => (bool) env('SADAD_REFUND_SUPPORTED', false),
                'void' => false,
                'authorize_capture' => false,
            ],
        ],
    ],

    'auctions' => [
        'cod_allowed_default' => false,
        'default_winner_payment_hours' => 48,
        'default_extension_window_seconds' => 120,
        'default_extension_seconds' => 120,
        'unpaid_winner_policy' => 'offer_next_bidder', // offer_next_bidder | relist | none
        'bid_rate_limit_per_minute' => 30,
    ],

    'loyalty' => [
        'enabled' => true,
        'points_per_qar' => 1,
        'redeem_value_per_point' => '0.05', // QAR per point
        'max_redeem_percent' => 30,
        'points_expiry_days' => 365,
        'referral_referrer_points' => 500,
        'referral_referee_points' => 250,
    ],

    'notifications' => [
        'abandoned_cart_after_minutes' => 120,
        'abandoned_cart_max_messages' => 2,
        'abandoned_cart_min_interval_hours' => 24,
        'max_attempts' => 5,
    ],

    'media' => [
        'disk' => env('MEDIA_DISK', 'public'),
        'max_image_kb' => 8192,
        'max_video_kb' => 102400,
        'image_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
        'video_mimes' => ['video/mp4', 'video/webm'],
        'thumbnail_widths' => [320, 640, 1280],
    ],
];
