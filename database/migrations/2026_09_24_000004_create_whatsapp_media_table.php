<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_message_id')->constrained()->cascadeOnDelete();
            $table->enum('kind', ['image', 'audio', 'video', 'document', 'sticker']);
            $table->string('mime_type', 127)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('filename')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->string('storage_disk', 32)->nullable();
            $table->string('storage_path', 500)->nullable();
            $table->string('provider_media_id', 200)->nullable();
            $table->enum('download_status', ['pending', 'done', 'failed'])->default('done');
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->enum('transcript_status', ['pending', 'done', 'failed', 'skipped'])->nullable();
            $table->text('transcript')->nullable();
            $table->string('transcript_language', 16)->nullable();
            $table->string('transcript_provider', 64)->nullable();
            $table->timestampTz('transcribed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['whatsapp_message_id', 'sha256']);
            $table->index('transcript_status');
            $table->index('download_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_media');
    }
};
