<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR Excel imports (Super Admin only). An uploaded workbook is parsed into STAGING rows and the file
 * itself is never stored. Staging rows hold the personal data needed to preview and process the import
 * (payload, name_key, phone_key, meta); all of that is purged or nulled 30 days after upload
 * (retention_expires_at, UTC) and the batch keeps only the non-sensitive audit trail: who imported,
 * when, totals and per-row outcome codes.
 *
 * Idempotency: a partial unique index allows only ONE live batch (staged, processing or completed) per
 * (type, SHA-256 of the file), so the same workbook cannot be imported twice by accident or by two
 * simultaneous uploads. Cancelled batches free the file again. Activation previews are read-only and
 * may be repeated, so they are excluded from the index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 30);
            $table->string('status', 20)->default('staged');
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->char('source_sha256', 64);
            $table->unsignedInteger('source_size');
            $table->string('source_extension', 10);

            // Staging counts (set once, at upload).
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);
            $table->unsignedInteger('duplicate_rows')->default(0);
            $table->unsignedInteger('review_rows')->default(0);

            // Outcome counts (updated as rows are processed).
            $table->unsignedInteger('imported_rows')->default(0);
            $table->unsignedInteger('skipped_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);

            $table->foreignId('confirmed_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // A short lease stops two requests from processing the same batch at once.
            $table->string('lease_token', 40)->nullable();
            $table->timestamp('lease_expires_at')->nullable();

            $table->timestamp('retention_expires_at');
            $table->timestamp('pii_purged_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index('retention_expires_at');
            $table->index('source_sha256');
        });

        DB::statement(
            'CREATE UNIQUE INDEX import_batches_live_source_unique ON import_batches (type, source_sha256) '
            ."WHERE status IN ('staged', 'processing', 'completed') AND type <> 'activation_preview'",
        );

        Schema::create('import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_batch_id')->constrained('import_batches')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('status', 20);
            $table->string('code', 60)->nullable();
            $table->string('message', 500)->nullable();

            // PERSONAL DATA - nulled by the retention purge.
            $table->json('payload')->nullable();
            $table->string('name_key', 255)->nullable();
            $table->string('phone_key', 20)->nullable();
            $table->json('meta')->nullable();

            // Non-sensitive link to the record the row created or matched; kept for the audit trail.
            $table->string('entity_type', 40)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['import_batch_id', 'row_number']);
            $table->index(['import_batch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
        DB::statement('DROP INDEX IF EXISTS import_batches_live_source_unique');
        Schema::dropIfExists('import_batches');
    }
};
