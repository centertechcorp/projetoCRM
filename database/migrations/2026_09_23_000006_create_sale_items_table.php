<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->string('gc_product_id', 32);
            $table->string('gc_variation_id', 32)->nullable();
            $table->string('product_name');
            $table->decimal('qty', 12, 3);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('unit_cost', 12, 2);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->string('price_table')->nullable();
            $table->boolean('moves_stock');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_items');
    }
};
