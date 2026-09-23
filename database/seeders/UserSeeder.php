<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['name' => 'Caio', 'role' => 'owner', 'owner_slot' => 1],
            ['name' => 'Matheus', 'role' => 'admin', 'owner_slot' => null],
            ['name' => 'Rodrigo', 'role' => 'admin', 'owner_slot' => null],
            ['name' => 'João Pedro', 'role' => 'manager', 'owner_slot' => null],
            ['name' => 'Vendedoras', 'role' => 'seller', 'owner_slot' => null],
        ] as $user) {
            User::query()->updateOrCreate(['name' => $user['name']], $user);
        }
    }
}
