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

    'mutool' => [
        // Path to the mutool (MuPDF CLI) binary used to normalize uploaded
        // PDFs — see App\Services\PdfNormalizer. Defaults to relying on PATH.
        'binary' => env('MUTOOL_BINARY', 'mutool'),
    ],

    'pyhanko' => [
        // Path to the Python interpreter with pyhanko installed (project
        // venv recommended — see scripts/pyhanko/README.md) used to apply
        // digital signatures — see App\Services\PyhankoPdfSigner. Defaults
        // to relying on `python` being on PATH.
        'python_binary' => env('PYHANKO_PYTHON_BINARY', 'python'),
    ],

];
