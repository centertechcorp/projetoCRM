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

    /*
    |--------------------------------------------------------------------------
    | WAHA (WhatsApp HTTP API)
    |--------------------------------------------------------------------------
    |
    | `webhook_hmac_key` valida a assinatura `X-Webhook-Hmac` que o WAHA manda em cada
    | POST (se vazio, nenhuma validação é feita — compatível com a configuração atual,
    | que ainda não assina). Precisa ser o mesmo valor configurado no WAHA
    | (WHATSAPP_HOOK_HMAC_KEY ou no `hmac.key` do webhook da sessão).
    |
    | `accounts` é usado por App\Console\Commands\WahaHealthCheck pra consultar o status
    | de cada sessão. base_url aponta pro host (as duas rodam fora da rede do Sail).
    |
    */

    'waha' => [
        'webhook_hmac_key' => env('WAHA_WEBHOOK_HMAC_KEY'),

        'accounts' => [
            'center' => [
                'base_url' => env('WAHA_CENTER_BASE_URL', 'http://host.docker.internal:3001'),
                'api_key' => env('WAHA_CENTER_API_KEY'),
                'session' => 'CENTER',
            ],
            'genius' => [
                'base_url' => env('WAHA_GENIUS_BASE_URL', 'http://host.docker.internal:3002'),
                'api_key' => env('WAHA_GENIUS_API_KEY'),
                'session' => 'GENIUS',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Follow-up de conversas paradas
    |--------------------------------------------------------------------------
    |
    | Regras do App\Services\Whatsapp\StalledConversationDetector: quando uma conversa
    | vira lead a reabordar. Horas/dias contados a partir da mensagem mais recente.
    |
    */

    'followup' => [
        // Só considera "parada" depois de tantas horas sem mensagem nova.
        'min_idle_hours' => (int) env('WHATSAPP_FOLLOWUP_MIN_IDLE_HOURS', 12),

        // Conversas mais antigas que isso não geram mais follow-up novo (só o que já existe).
        'max_age_days' => (int) env('WHATSAPP_FOLLOWUP_MAX_AGE_DAYS', 15),

        // Não gera um follow-up novo para a mesma conversa antes desse intervalo.
        'cooldown_days' => (int) env('WHATSAPP_FOLLOWUP_COOLDOWN_DAYS', 10),

        // Por quantos dias um lead "comprou" fica visível no pós-venda antes de arquivar.
        'retention_days' => (int) env('WHATSAPP_FOLLOWUP_RETENTION_DAYS', 30),

        // Não sugere mais reabordagem nova depois de já ter tentado esse tanto de vezes.
        'max_attempts' => (int) env('WHATSAPP_FOLLOWUP_MAX_ATTEMPTS', 3),

        // Quantos dias depois de marcado como perdido o App\Services\Leads\LostLeadReconnector
        // tenta reconectar de novo (ninguém fica de fora, nem quem já disse que não queria nada).
        'lost_reconnect_after_days' => (int) env('WHATSAPP_FOLLOWUP_LOST_RECONNECT_AFTER_DAYS', 5),

        // Se a última mensagem do cliente for só uma dessas (sem acento/pontuação, minúsculas),
        // a conversa é tratada como encerrada, não como "sem resposta".
        'closing_phrases' => [
            'obrigado', 'obrigada', 'ok', 'okay', 'blz', 'beleza',
            'valeu', 'de nada', 'por nada',
            'entendi', 'certo', 'tranquilo', 'tudo bem',
            '👍', '👍🏻', '👍🏼', '👍🏽', '👍🏾', '👍🏿',
        ],

        // Números que nunca viram lead, mesmo se a conversa parar — telefone do dono/equipe
        // usado pra testar a extensão, não é cliente de verdade. Mesmo formato de
        // customers.phone (só dígitos, com DDI). Lista separada por vírgula.
        'excluded_phones' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('WHATSAPP_FOLLOWUP_EXCLUDED_PHONES', '5534999748837'))
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mensagens de grupo
    |--------------------------------------------------------------------------
    |
    | Grupo não vira lead (App\Services\Whatsapp\StalledConversationDetector só olha
    | conversa individual), mas ainda assim dá pra guardar o que interessa. Grupos na
    | denylist são ignorados por completo assim que aparecem (nada é gravado, nem
    | checa assunto). Os demais passam pelo App\Services\Whatsapp\ProductTopicMatcher:
    | só é gravado em whatsapp_group_messages se falar de algum termo da lista e não
    | parecer catálogo de preço.
    |
    */

    'group_denylist' => [
        'Compra, Venda e troca de Frutal-MG',
        'VENDAS DE FRUTAL-MG',
        'VIP CENTER TECH',
        'Center Tech equipe',
        'PEDIDOS CENTER TECH',
        'ORÇAMENTO CENTER TECH',
        'FRETE CAIO FRUTAL x BARRETOS',
    ],

    'group_topics' => [
        'keywords' => [
            'celular', 'smartphone', 'iphone', 'ipad', 'samsung', 'xiaomi', 'motorola', 'redmi',
            'notebook', 'tablet', 'carregador', 'fone', 'fone de ouvido', 'headset', 'airpods',
            'capinha', 'capa', 'pelicula', 'película', 'tela', 'display', 'bateria', 'placa',
            'peca', 'peça', 'eletronico', 'eletrônico', 'imei', 'gb',
        ],

        // Mensagem com mais de tantos "R$" vira tabela de preço/catálogo, não pergunta de
        // cliente — ignorada mesmo citando produto.
        'max_price_mentions' => (int) env('WHATSAPP_GROUP_MAX_PRICE_MENTIONS', 1),
    ],

];
