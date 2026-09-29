<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_chats', function (Blueprint $table) {
            $table->boolean('ignored')->default(false)->after('kind');
        });

        // Grupo não é conversa de cliente individual: os já capturados até agora (classificados
        // gerais, nada do Center Tech) começam ignorados. Quem quiser reativar um específico usa
        // whatsapp:chats:ignore.
        DB::table('whatsapp_chats')->where('kind', 'group')->update(['ignored' => true]);
    }

    public function down(): void
    {
        Schema::table('whatsapp_chats', function (Blueprint $table) {
            $table->dropColumn('ignored');
        });
    }
};
