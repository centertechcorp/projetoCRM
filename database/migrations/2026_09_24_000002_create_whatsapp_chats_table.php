<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_chats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_account_id')->constrained()->restrictOnDelete();
            $table->string('chat_key', 64);
            $table->string('jid', 200);
            $table->enum('kind', ['individual', 'group']);
            $table->string('phone', 32)->nullable();
            $table->string('display_name')->nullable();
            $table->timestampTz('last_message_at')->nullable();
            $table->timestampsTz();
            $table->unique(['whatsapp_account_id', 'chat_key']);
            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_chats');
    }
};
