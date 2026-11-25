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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'paystack' => [
        'secret' => env('PAYSTACK_SECRET_KEY'),
    ],

    'flutterwave' => [
        'secret' => env('FLUTTERWAVE_SECRET_KEY'),
    ],

    'razorpay' => [
        'secret' => env('RAZORPAY_KEY_SECRET'),
    ],
    'care360_places' => [
        'provider' => env('CARE360_PLACES_PROVIDER', 'google'),
        'geoapify_key' => env('GEOAPIFY_API_KEY', ''),
    ],
    'google_places' => [
        // Dedicated backend-only Places API (New) key, restricted by server IP.
        'key' => env('K3_PLACES_SERVER_API_KEY', ''),
    ],

    'care360_ai' => [
        'enabled' => env('K3_AI_GUIDE_ENABLED', false),
        'key' => env('K3_AI_GEMINI_API_KEY'),
    ],

];
