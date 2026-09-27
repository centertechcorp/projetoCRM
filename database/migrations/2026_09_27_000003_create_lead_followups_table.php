<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_followups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['recover', 'upsell']);
            $table->string('reason', 32);
            $table->enum('status', ['candidate', 'draft_ready', 'approved', 'dismissed', 'sent'])->default('candidate');
            $table->text('suggested_message')->nullable();
            $table->string('suggested_by', 16)->default('rules');
            $table->string('confidence', 16)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('dismissed_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();
            $table->index(['lead_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_followups');
    }
};
