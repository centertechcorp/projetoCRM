<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

#[Signature('user:password {email : E-mail de login} {--name= : Nome já cadastrado em users, para definir o e-mail dele agora}')]
#[Description('Define e-mail e senha de um usuário para ele conseguir entrar no painel')]
class UserSetPassword extends Command
{
    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $name = $this->option('name');

        if ($name !== null) {
            $user = User::query()->where('name', $name)->first();

            if ($user === null) {
                $this->components->error("Nenhum usuário chamado \"{$name}\".");

                return self::FAILURE;
            }

            if (User::query()->where('email', $email)->where('id', '!=', $user->id)->exists()) {
                $this->components->error('Esse e-mail já está em uso por outro usuário.');

                return self::FAILURE;
            }
        } else {
            $user = User::query()->where('email', $email)->first();

            if ($user === null) {
                $this->components->error('Usuário não encontrado. Use --name="Nome já cadastrado" para definir o e-mail dele.');

                return self::FAILURE;
            }
        }

        $password = $this->secret('Nova senha (mínimo 8 caracteres)');
        $confirmation = $this->secret('Confirme a senha');

        if ($password !== $confirmation) {
            $this->components->error('As senhas não coincidem.');

            return self::FAILURE;
        }

        if (strlen((string) $password) < 8) {
            $this->components->error('A senha precisa ter pelo menos 8 caracteres.');

            return self::FAILURE;
        }

        $user->update(['email' => $email, 'password' => Hash::make($password)]);

        $this->components->info("Senha definida para {$user->name} ({$email}).");

        return self::SUCCESS;
    }
}
