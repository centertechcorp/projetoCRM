<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('gc_raw_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('entity', 32);
            $table->string('gc_id', 32);
            $table->jsonb('payload');
            $table->char('payload_hash', 64);
            $table->timestampTz('gc_modified_at')->nullable();
            $table->timestampTz('fetched_at');
            $table->foreignId('sync_run_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['store_id', 'entity', 'gc_id']);
        });

        DB::statement('CREATE INDEX gc_raw_records_payload_gin ON gc_raw_records USING GIN (payload)');
    }

    public function down(): void
    {
        Schema::dropIfExists('gc_raw_records');
    }
};
