<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'sms' => [
        'driver' => env('SMS_DRIVER', 'null'), // null | http
        'sender_id' => env('SMS_SENDER_ID', 'ALBAHAR'),
        'http' => [
            'url' => env('SMS_HTTP_URL'),
            'auth_header' => env('SMS_HTTP_AUTH_HEADER', 'Authorization'),
            'auth_value' => env('SMS_HTTP_AUTH_VALUE'),
            'fields' => [
                'to' => env('SMS_HTTP_FIELD_TO', 'to'),
                'message' => env('SMS_HTTP_FIELD_MESSAGE', 'message'),
                'sender' => env('SMS_HTTP_FIELD_SENDER', 'sender'),
                'message_id' => env('SMS_HTTP_FIELD_MESSAGE_ID', 'id'),
            ],
        ],
    ],

    'fcm' => [
        'project_id' => env('FCM_PROJECT_ID'),
        'credentials_path' => env('FCM_CREDENTIALS_PATH'),
    ],

    'whatsapp' => [
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),
    ],

    'google_maps' => [
        'key' => env('GOOGLE_MAPS_API_KEY'),
    ],

];
