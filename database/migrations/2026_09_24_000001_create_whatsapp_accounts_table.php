<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('label');
            $table->string('phone', 32)->nullable();
            $table->string('provider', 32);
            $table->string('provider_account_ref', 128)->nullable();
            $table->char('token_hash', 64)->nullable()->unique();
            $table->text('provider_config')->nullable();
            $table->boolean('active')->default(true);
            $table->timestampsTz();
            $table->unique(['provider', 'provider_account_ref']);
            $table->index('store_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_accounts');
    }
};
