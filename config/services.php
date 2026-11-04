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

    // Segredo do JWT emitido pela Lambda lambda-auth-cpf (repositório
    // separado). DIFERENTE do JWT_SECRET do jwt-auth/guard "api".
    'customer_jwt' => [
        'secret' => env('CUSTOMER_JWT_SECRET'),
    ],

    // execution-service: consulta síncrona (REST) ao catálogo ao abrir a OS.
    'execution' => [
        'url' => env('EXECUTION_SERVICE_URL', 'http://localhost:8083'),
        'timeout' => (int) env('EXECUTION_SERVICE_TIMEOUT', 5),
    ],

    // Token compartilhado com a Lambda lambda-auth-cpf para o endpoint
    // interno de busca de cliente por CPF (a Lambda não acessa mais o banco
    // do os-service diretamente).
    'internal_api' => [
        'token' => env('INTERNAL_API_TOKEN'),
    ],

];
