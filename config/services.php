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
    'openai' => [
        'key' => env('OPENAI_API_KEY'),
    ],

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
    ],

    'openvoice' => [
        'url' => env('OPENVOICE_SERVICE_URL', 'http://localhost:8005'),
    ],

    'whisperx' => [
        'url' => env('WHISPERX_SERVICE_URL', 'http://whisperx:8000'),
    ],

    'demucs' => [
        'url' => env('DEMUCS_SERVICE_URL', 'http://demucs:8000'),
    ],

    'aisha' => [
        'url' => env('AISHA_API_URL', 'https://back.aisha.group/api/v1'),
        'api_key' => env('AISHA_API_KEY', ''),
    ],

    'uzbekvoice' => [
        'url' => env('UZBEKVOICE_API_URL', 'https://uzbekvoice.ai/api/v1'),
        'api_key' => env('UZBEKVOICE_API_KEY', ''),
    ],

    'xtts' => [
        'url' => env('XTTS_SERVICE_URL', 'http://localhost:8001'),
    ],

    'f5tts' => [
        'url' => env('F5TTS_SERVICE_URL', 'http://localhost:8004'),
    ],

    'mms_tts' => [
        'url' => env('MMS_TTS_SERVICE_URL', 'http://localhost:8005'),
    ],

    'elevenlabs' => [
        'api_key' => env('ELEVENLABS_API_KEY', ''),
    ],

    'runpod' => [
        'api_key' => env('RUNPOD_API_KEY', ''),
        'whisperx_endpoint_id' => env('RUNPOD_WHISPERX_ENDPOINT_ID', ''),
        'demucs_endpoint_id' => env('RUNPOD_DEMUCS_ENDPOINT_ID', ''),
    ],
];
