<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Só leitura, pra conferir no DBeaver quem é quem (loja/conta) sem juntar tabela na mão.
        // No MariaDB a view nasce com SQL SECURITY INVOKER: sem isso ela fica presa ao usuário que
        // rodou a migration (DEFINER) e quebra ao restaurar o banco em outro servidor/usuário.
        $security = in_array(DB::getDriverName(), ['mariadb', 'mysql'], true) ? 'SQL SECURITY INVOKER' : '';

        DB::statement("
            CREATE OR REPLACE {$security} VIEW whatsapp_messages_view AS
            SELECT wm.*, s.code AS loja, wa.label AS conta
            FROM whatsapp_messages wm
            JOIN whatsapp_accounts wa ON wa.id = wm.whatsapp_account_id
            JOIN stores s ON s.id = wa.store_id
        ");
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS whatsapp_messages_view');
    }
};
