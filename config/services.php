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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
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

    'airtel_sms' => [
        'base_url' => env('AIRTEL_SMS_BASE_URL', 'https://www.airtel.sd'),
        'endpoint' => env('AIRTEL_SMS_ENDPOINT', '/api/rest_send_sms/'),
        'api_key' => env('AIRTEL_SMS_API_KEY'),
        'default_sender' => env('AIRTEL_SMS_SENDER', 'Jawda'),
        'timeout' => env('AIRTEL_SMS_TIMEOUT', 10),
        // Optional credentials if needed in future
        'user_id' => env('AIRTEL_SMS_USER_ID'),
        'api_id' => env('AIRTEL_SMS_API_ID'),
        'user_identifier' => env('AIRTEL_SMS_USER_IDENTIFIER'),
        'password' => env('AIRTEL_SMS_PASSWORD'),
    ],

];
