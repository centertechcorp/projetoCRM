<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Quem pode aprovar/descartar sugestão e marcar lead como vendido/perdido no painel.
        // É por pessoa, não por papel: nem todo admin deve poder decidir.
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_decide')->default(false)->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('can_decide');
        });
    }
};
