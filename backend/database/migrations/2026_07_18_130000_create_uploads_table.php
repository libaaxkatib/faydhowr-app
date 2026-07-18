<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uploads', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('customer_profile_id')
                ->constrained()
                ->restrictOnDelete();
            $table->string('disk', 100);
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('media_type', 20);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size_bytes');
            $table->timestamp('attached_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['customer_profile_id', 'created_at']);
            $table->index(['attached_at', 'expires_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE uploads ADD CONSTRAINT uploads_media_type_check '
                ."CHECK (media_type IN ('image', 'video', 'document'))",
            );
            DB::statement(
                'ALTER TABLE uploads ADD CONSTRAINT uploads_file_size_bytes_check '
                .'CHECK (file_size_bytes > 0)',
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('uploads');
    }
};
