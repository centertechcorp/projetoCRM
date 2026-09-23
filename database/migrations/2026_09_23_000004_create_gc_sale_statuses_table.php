<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('gc_sale_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('gc_status_id', 32);
            $table->string('name');
            $table->enum('mapped_status', ['aberta', 'confirmada', 'cancelada'])->nullable();
            $table->timestampsTz();
            $table->unique(['store_id', 'gc_status_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gc_sale_statuses');
    }
};
