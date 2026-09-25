<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Models\WhatsappAccount;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('whatsapp:account {store : Código da loja (CENTER, GENIUS ou MIXCELL)} {label : Nome da conta} {--phone= : Número do WhatsApp, só dígitos} {--provider=web_extension : Provedor das mensagens}')]
#[Description('Cria uma conta de WhatsApp para uma loja e mostra o token da extensão (uma vez só)')]
class WhatsappAccountCreate extends Command
{
    public function handle(): int
    {
        $store = Store::query()->where('code', strtoupper((string) $this->argument('store')))->first();

        if ($store === null) {
            $this->components->error('Loja não encontrada. Códigos: '.Store::query()->pluck('code')->implode(', ').'.');

            return self::FAILURE;
        }

        $phone = $this->option('phone');

        if ($phone !== null && preg_match('/^\d{8,20}$/', $phone) !== 1) {
            $this->components->error('--phone deve ter só dígitos, com código do país (ex.: 5519999998888).');

            return self::FAILURE;
        }

        $provider = (string) $this->option('provider');
        $token = $provider === 'web_extension' ? Str::random(40) : null;

        WhatsappAccount::create([
            'store_id' => $store->id,
            'label' => (string) $this->argument('label'),
            'phone' => $phone,
            'provider' => $provider,
            'token_hash' => $token !== null ? WhatsappAccount::hashToken($token) : null,
        ]);

        $this->components->info("Conta criada para {$store->code}.");

        if ($token !== null) {
            $this->line('Token da extensão (guarde agora; não dá para ver de novo):');
            $this->line($token);
        }

        return self::SUCCESS;
    }
}
