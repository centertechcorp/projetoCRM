<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Models\WhatsappAccount;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('whatsapp:account:token {store : Código da loja (CENTER, GENIUS ou MIXCELL)} {--provider=web_extension : Provedor da conta} {--id= : ID da conta, obrigatório se a loja tiver mais de uma conta com esse provedor}')]
#[Description('Gera um novo token para uma conta de WhatsApp já existente e mostra ele (uma vez só)')]
class WhatsappAccountRegenerateToken extends Command
{
    public function handle(): int
    {
        $store = Store::query()->where('code', strtoupper((string) $this->argument('store')))->first();

        if ($store === null) {
            $this->components->error('Loja não encontrada. Códigos: '.Store::query()->pluck('code')->implode(', ').'.');

            return self::FAILURE;
        }

        $provider = (string) $this->option('provider');

        $matches = WhatsappAccount::query()
            ->where('store_id', $store->id)
            ->where('provider', $provider)
            ->get();

        if ($matches->isEmpty()) {
            $this->components->error("Nenhuma conta {$provider} encontrada para {$store->code}. Use whatsapp:account para criar uma.");

            return self::FAILURE;
        }

        if ($matches->count() > 1) {
            $id = $this->option('id');

            if ($id === null) {
                $this->components->error("Mais de uma conta {$provider} para {$store->code}. Use --id para escolher:");
                foreach ($matches as $candidate) {
                    $this->line("  id={$candidate->id} | {$candidate->label}");
                }

                return self::FAILURE;
            }

            $account = $matches->firstWhere('id', (int) $id);

            if ($account === null) {
                $this->components->error("Nenhuma conta com id={$id} entre as contas {$provider} de {$store->code}.");

                return self::FAILURE;
            }
        } else {
            $account = $matches->first();
        }

        $token = Str::random(40);

        $account->update(['token_hash' => WhatsappAccount::hashToken($token)]);

        $this->components->info("Token renovado para {$store->code} ({$account->label}). O token antigo parou de funcionar.");
        $this->line('Token da extensão (guarde agora; não dá para ver de novo):');
        $this->line($token);

        return self::SUCCESS;
    }
}
