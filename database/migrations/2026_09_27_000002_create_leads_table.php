<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('whatsapp_chat_id')->nullable()->constrained()->nullOnDelete();
            $table->text('product_interest')->nullable();
            $table->jsonb('products')->nullable();
            $table->decimal('quoted_amount', 12, 2)->nullable();
            $table->string('source', 32)->default('whatsapp');
            $table->string('source_detail')->nullable();
            $table->foreignId('attended_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('attended_by_label')->nullable();
            $table->enum('status', ['open', 'won', 'lost'])->default('open');
            $table->timestampTz('won_at')->nullable();
            $table->timestampTz('lost_at')->nullable();
            $table->string('lost_reason')->nullable();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('last_contact_at')->nullable();
            $table->timestampTz('next_contact_at')->nullable();
            $table->timestampTz('archive_after')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['status', 'next_contact_at']);
            $table->index(['store_id', 'status']);
        });

        // Só 1 lead em aberto por cliente e loja; permite histórico de leads fechados/perdidos.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX leads_open_customer_store_unique
                ON leads (customer_id, store_id)
                WHERE status = 'open' AND deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
