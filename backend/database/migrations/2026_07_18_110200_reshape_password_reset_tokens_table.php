<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replace the framework-default table (email PK, plaintext-style token)
     * with the documented structure (Database Design §3.1.7): subject-bound,
     * hashed, single-use, expiring tokens.
     */
    public function up(): void
    {
        Schema::dropIfExists('password_reset_tokens');

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('subject_type', 30);
            $table->unsignedBigInteger('subject_id');
            $table->string('token_hash');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at');

            $table->index(['subject_type', 'subject_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }
};
