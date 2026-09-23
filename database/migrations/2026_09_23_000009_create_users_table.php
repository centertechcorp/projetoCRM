<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->timestampTz('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->enum('role', ['owner', 'admin', 'manager', 'seller']);
            $table->unsignedSmallInteger('owner_slot')->nullable()->unique();
            $table->rememberToken();
            $table->timestampsTz();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE users ADD CONSTRAINT users_role_owner_slot_check CHECK (
                (role = 'owner' AND owner_slot IN (1, 2))
                OR (role <> 'owner' AND owner_slot IS NULL)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
