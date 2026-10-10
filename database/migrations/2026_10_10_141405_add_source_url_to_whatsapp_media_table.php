<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_media', function (Blueprint $table) {
            // Link do WAHA pra baixar o arquivo (expira/some depois de um tempo do lado deles —
            // guardamos pra poder baixar de verdade no job, mesmo que a mensagem seja gravada antes).
            $table->string('source_url', 500)->nullable()->after('provider_media_id');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_media', function (Blueprint $table) {
            $table->dropColumn('source_url');
        });
    }
};
