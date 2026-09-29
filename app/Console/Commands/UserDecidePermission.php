<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('user:decide {name : Nome já cadastrado em users} {state : on ou off}')]
#[Description('Liga ou desliga a permissão de um usuário aprovar/descartar/decidir leads no painel')]
class UserDecidePermission extends Command
{
    public function handle(): int
    {
        $state = (string) $this->argument('state');

        if (! in_array($state, ['on', 'off'], true)) {
            $this->components->error('state deve ser "on" ou "off".');

            return self::FAILURE;
        }

        $user = User::query()->where('name', $this->argument('name'))->first();

        if ($user === null) {
            $this->components->error('Usuário não encontrado.');

            return self::FAILURE;
        }

        $user->update(['can_decide' => $state === 'on']);

        $this->components->info("{$user->name} ".($state === 'on' ? 'agora pode' : 'não pode mais').' decidir leads no painel.');

        return self::SUCCESS;
    }
}
