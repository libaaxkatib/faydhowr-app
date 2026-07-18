<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Discussion message attachments (Sprint 28, Database Design §3.5.3B).
     * Replaces the legacy JSON attachment blobs with FK references to the
     * Unified Upload Service; this is the only channel for additional
     * customer files after Submit.
     */
    public function up(): void
    {
        Schema::create('quotation_message_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quotation_discussion_message_id')
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('upload_id')
                ->unique()
                ->constrained()
                ->restrictOnDelete();
            $table->timestampTz('created_at')->nullable();

            $table->index(
                ['quotation_discussion_message_id', 'created_at'],
                'quotation_message_attachments_message_created_index',
            );
        });

        Schema::table('quotation_discussion_messages', function (Blueprint $table): void {
            $table->dropColumn('attachments');
        });
    }

    public function down(): void
    {
        Schema::table('quotation_discussion_messages', function (Blueprint $table): void {
            if (DB::getDriverName() === 'pgsql') {
                $table->jsonb('attachments')->nullable();
            } else {
                $table->json('attachments')->nullable();
            }
        });

        Schema::dropIfExists('quotation_message_attachments');
    }
};
