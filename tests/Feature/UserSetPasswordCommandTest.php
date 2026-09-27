<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserSetPasswordCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_sets_email_and_password_for_an_existing_user_by_name(): void
    {
        User::create(['name' => 'Caio', 'role' => 'owner', 'owner_slot' => 1]);

        $this->artisan('user:password', ['email' => 'caio@center.com', '--name' => 'Caio'])
            ->expectsQuestion('Nova senha (mínimo 8 caracteres)', 'senha-boa-123')
            ->expectsQuestion('Confirme a senha', 'senha-boa-123')
            ->assertSuccessful();

        $user = User::where('name', 'Caio')->sole();
        $this->assertSame('caio@center.com', $user->email);
        $this->assertTrue(Hash::check('senha-boa-123', $user->password));
    }

    public function test_fails_when_passwords_do_not_match(): void
    {
        User::create(['name' => 'Caio', 'role' => 'owner', 'owner_slot' => 1]);

        $this->artisan('user:password', ['email' => 'caio@center.com', '--name' => 'Caio'])
            ->expectsQuestion('Nova senha (mínimo 8 caracteres)', 'senha-boa-123')
            ->expectsQuestion('Confirme a senha', 'outra-coisa')
            ->assertFailed();

        $this->assertNull(User::where('name', 'Caio')->sole()->password);
    }

    public function test_fails_for_a_short_password(): void
    {
        User::create(['name' => 'Caio', 'role' => 'owner', 'owner_slot' => 1]);

        $this->artisan('user:password', ['email' => 'caio@center.com', '--name' => 'Caio'])
            ->expectsQuestion('Nova senha (mínimo 8 caracteres)', '123')
            ->expectsQuestion('Confirme a senha', '123')
            ->assertFailed();
    }

    public function test_fails_when_neither_name_nor_matching_email_exists(): void
    {
        $this->artisan('user:password', ['email' => 'ninguem@center.com'])->assertFailed();
    }
}
