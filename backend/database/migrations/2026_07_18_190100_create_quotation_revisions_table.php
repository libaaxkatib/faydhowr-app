<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Immutable, sequentially versioned pricing revisions (Sprint 28,
     * Database Design §3.5.3). All pricing lives here; the quotation head
     * keeps `latest_revision_id` pointing at the highest version_number.
     */
    public function up(): void
    {
        Schema::create('quotation_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quotation_id')
                ->constrained()
                ->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('source', 30)->default('admin_issue');
            $table->decimal('subtotal_amount', 12, 2);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2);
            $table->timestampTz('valid_until');
            $table->text('terms')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('issued_by_admin_id')
                ->nullable()
                ->constrained('admins')
                ->nullOnDelete();
            $table->timestampTz('created_at')->nullable();

            $table->unique(['quotation_id', 'version_number']);
            $table->index(['quotation_id', 'created_at']);
        });

        Schema::table('quotations', function (Blueprint $table): void {
            $table->foreignId('latest_revision_id')
                ->nullable()
                ->after('assigned_admin_id')
                ->constrained('quotation_revisions')
                ->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE quotation_revisions ADD CONSTRAINT quotation_revisions_source_check '
                ."CHECK (source IN ('admin_issue', 'system_migration'))",
            );
            DB::statement(
                'ALTER TABLE quotation_revisions ADD CONSTRAINT quotation_revisions_version_number_check '
                .'CHECK (version_number >= 1)',
            );
            DB::statement(
                'ALTER TABLE quotation_revisions ADD CONSTRAINT quotation_revisions_amounts_non_negative_check '
                .'CHECK (subtotal_amount >= 0 AND discount_amount >= 0 AND tax_amount >= 0 '
                .'AND total_amount >= 0 AND discount_amount <= subtotal_amount)',
            );
            DB::statement(
                'ALTER TABLE quotation_revisions ADD CONSTRAINT quotation_revisions_total_amount_check '
                .'CHECK (total_amount = subtotal_amount - discount_amount + tax_amount)',
            );
            DB::statement(
                'ALTER TABLE quotation_revisions ADD CONSTRAINT quotation_revisions_issuer_check '
                ."CHECK (source = 'system_migration' OR issued_by_admin_id IS NOT NULL)",
            );
        }
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('latest_revision_id');
        });

        Schema::dropIfExists('quotation_revisions');
    }
};
