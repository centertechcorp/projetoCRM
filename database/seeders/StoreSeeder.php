<?php

namespace Database\Seeders;

use App\Models\Store;
use Illuminate\Database\Seeder;

class StoreSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['code' => 'CENTER', 'name' => 'CENTER - Varejo, Planura'],
            ['code' => 'GENIUS', 'name' => 'GENIUS - Varejo, Frutal'],
            ['code' => 'MIXCELL', 'name' => 'MIXCELL - Atacado, Frutal'],
        ] as $store) {
            Store::query()->updateOrCreate(['code' => $store['code']], $store);
        }
    }
}
