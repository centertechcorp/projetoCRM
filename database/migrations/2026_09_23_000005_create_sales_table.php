<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('gc_sale_id', 32);
            $table->string('gc_code', 32);
            $table->string('gc_public_hash')->nullable();
            $table->string('gc_client_id', 32)->nullable();
            $table->string('client_name')->nullable();
            $table->string('gc_seller_id', 32)->nullable();
            $table->string('seller_name')->nullable();
            $table->string('gc_status_id', 32);
            $table->enum('status', ['aberta', 'confirmada', 'cancelada', 'desconhecida'])->default('desconhecida');
            $table->date('sale_date');
            $table->timestampTz('concluded_at')->nullable();
            $table->string('channel')->nullable();
            $table->string('store_branch_name')->nullable();
            $table->decimal('products_amount', 12, 2)->default(0);
            $table->decimal('services_amount', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('freight', 12, 2)->default(0);
            $table->decimal('gross', 12, 2)->default(0);
            $table->decimal('net', 12, 2)->default(0);
            $table->decimal('cost', 12, 2)->default(0);
            $table->string('payment_condition')->nullable();
            $table->integer('installments')->nullable();
            $table->timestampTz('gc_created_at');
            $table->timestampTz('gc_modified_at');
            $table->timestampsTz();
            $table->unique(['store_id', 'gc_sale_id']);
            $table->index(['store_id', 'sale_date']);
            $table->index('status');
            $table->index('gc_client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
