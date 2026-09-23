<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('entity', 32);
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->enum('status', ['running', 'ok', 'error']);
            $table->integer('rows_in')->default(0);
            $table->integer('rows_changed')->default(0);
            $table->text('error')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};
