<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('whatsapp_chat_id')->constrained()->restrictOnDelete();
            $table->string('external_id', 200);
            $table->enum('direction', ['in', 'out']);
            $table->string('sender_name')->nullable();
            $table->string('sender_jid', 200)->nullable();
            $table->string('sent_via', 16)->nullable();
            $table->string('quoted_external_id', 200)->nullable();
            $table->string('type', 40);
            $table->text('body')->default('');
            $table->timestampTz('sent_at');
            $table->string('source', 32);
            $table->jsonb('payload')->nullable();
            $table->timestampsTz();
            $table->unique(['whatsapp_account_id', 'external_id']);
            $table->index(['whatsapp_chat_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};
