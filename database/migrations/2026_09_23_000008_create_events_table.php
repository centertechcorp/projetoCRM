<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('type', 64);
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject_type', 64);
            $table->unsignedBigInteger('subject_id');
            $table->jsonb('payload')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();
            $table->index(['type', 'processed_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
