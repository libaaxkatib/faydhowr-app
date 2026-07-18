<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quotation request attachments (Sprint 28, Database Design §3.5.2).
     * References staged files in the Unified Upload Service by FK; upload
     * metadata is never duplicated. Attach/detach only while `draft`.
     */
    public function up(): void
    {
        Schema::create('quotation_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quotation_id')
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('upload_id')
                ->unique()
                ->constrained()
                ->restrictOnDelete();
            $table->timestampTz('created_at')->nullable();

            $table->index(['quotation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_attachments');
    }
};
