<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_group_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_account_id')->constrained()->restrictOnDelete();
            $table->string('external_id', 200);
            $table->string('group_jid', 200);
            $table->string('group_name')->nullable();
            $table->string('sender_jid', 200)->nullable();
            $table->string('sender_name')->nullable();
            $table->text('body');
            $table->timestampTz('sent_at');
            $table->timestampsTz();
            $table->unique(['whatsapp_account_id', 'external_id']);
            $table->index(['whatsapp_account_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_group_messages');
    }
};
