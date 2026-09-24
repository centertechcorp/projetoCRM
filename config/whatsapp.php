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

    // Um arquivo .txt por contato, somente com acréscimos (append-only).
    'path' => storage_path('app/whatsapp'),

    'max_body_bytes' => 1024 * 1024,

];
