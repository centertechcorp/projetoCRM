<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Daemon de mensagens do WhatsApp
    |--------------------------------------------------------------------------
    |
    | Endereço em que `php artisan whatsapp:listen` escuta as mensagens enviadas
    | pela extensão do Chrome. O token é obrigatório e deve ser o mesmo
    | configurado na página de opções da extensão.
    |
    */

    'host' => env('WHATSAPP_HOST', '127.0.0.1'),

    'port' => (int) env('WHATSAPP_PORT', 8765),

    'token' => env('WHATSAPP_TOKEN'),

    // Grava as mensagens no banco. O token passa a ser o de cada conta (whatsapp:account),
    // e WHATSAPP_TOKEN deixa de ser usado. No host o DB_HOST precisa ser 127.0.0.1.
    'database' => (bool) env('WHATSAPP_DATABASE', false),

    // Um arquivo .txt por contato, somente com acréscimos (append-only).
    'path' => storage_path('app/whatsapp'),

    'max_body_bytes' => 1024 * 1024,

    /*
    |--------------------------------------------------------------------------
    | Webhook da Meta Cloud API
    |--------------------------------------------------------------------------
    |
    | `verify_token` é o valor arbitrário informado na configuração do webhook no painel
    | da Meta (ela o devolve na checagem GET). `app_secret` é o segredo do app, usado para
    | validar a assinatura `X-Hub-Signature-256` de cada requisição POST.
    |
    */

    'meta' => [
        'verify_token' => env('META_WHATSAPP_VERIFY_TOKEN'),
        'app_secret' => env('META_WHATSAPP_APP_SECRET'),
    ],

];
