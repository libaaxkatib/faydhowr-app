<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('booking_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')
                ->constrained()
                ->restrictOnDelete();
            $table->string('media_type', 10);
            $table->string('disk', 100);
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampTz('uploaded_at');
            $table->timestamps();

            $table->index(['booking_id', 'sort_order']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE booking_media ADD CONSTRAINT booking_media_type_check '
                ."CHECK (media_type IN ('image', 'video'))",
            );
            DB::statement(
                'ALTER TABLE booking_media ADD CONSTRAINT booking_media_file_size_check '
                .'CHECK (file_size > 0)',
            );
            DB::statement(
                'ALTER TABLE booking_media ADD CONSTRAINT booking_media_mime_type_check '
                ."CHECK ((media_type = 'image' AND mime_type IN ('image/jpeg', 'image/png', 'image/webp')) "
                ."OR (media_type = 'video' AND mime_type IN ('video/mp4', 'video/quicktime', 'video/webm')))",
            );
            DB::statement(
                'ALTER TABLE booking_media ADD CONSTRAINT booking_media_extension_check '
                ."CHECK ((media_type = 'image' AND lower(original_name) ~ '\\.(jpg|jpeg|png|webp)$') "
                ."OR (media_type = 'video' AND lower(original_name) ~ '\\.(mp4|mov|webm)$'))",
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_media');
    }
};
