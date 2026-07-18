<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 28 quotation workflow: request context columns, single reviewer
     * assignment, submit timestamp, and the eight-state lifecycle. Legacy
     * `pending_review` rows are remapped to `submitted` (Database Design §3.5.1).
     */
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table): void {
            $table->text('requirements')->nullable()->after('booking_id');
            $table->text('description')->nullable()->after('requirements');
            $table->string('preferred_timing')->nullable()->after('description');
            $table->integer('quantity_hint')->nullable()->after('preferred_timing');
            $table->foreignId('assigned_admin_id')
                ->nullable()
                ->after('quantity_hint')
                ->constrained('admins')
                ->nullOnDelete();
            $table->timestampTz('submitted_at')->nullable()->after('valid_until');

            $table->index(['status', 'assigned_admin_id']);
            $table->index(['customer_profile_id', 'status']);
        });

        DB::table('quotations')
            ->where('status', 'pending_review')
            ->update(['status' => 'submitted', 'submitted_at' => DB::raw('created_at')]);

        DB::table('quotation_status_histories')
            ->where('status', 'pending_review')
            ->update(['status' => 'submitted']);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE quotations DROP CONSTRAINT IF EXISTS quotations_status_check');
            DB::statement(
                'ALTER TABLE quotations ADD CONSTRAINT quotations_status_check '
                ."CHECK (status IN ('draft', 'submitted', 'under_review', 'quotation_ready', "
                ."'under_discussion', 'accepted', 'expired', 'cancelled'))",
            );
            DB::statement("ALTER TABLE quotations ALTER COLUMN status SET DEFAULT 'draft'");

            DB::statement('ALTER TABLE quotation_status_histories DROP CONSTRAINT IF EXISTS quotation_status_histories_status_check');
            DB::statement(
                'ALTER TABLE quotation_status_histories ADD CONSTRAINT quotation_status_histories_status_check '
                ."CHECK (status IN ('draft', 'submitted', 'under_review', 'quotation_ready', "
                ."'under_discussion', 'accepted', 'expired', 'cancelled'))",
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE quotation_status_histories DROP CONSTRAINT IF EXISTS quotation_status_histories_status_check');
            DB::statement(
                'ALTER TABLE quotation_status_histories ADD CONSTRAINT quotation_status_histories_status_check '
                ."CHECK (status IN ('pending_review', 'quotation_ready', 'under_discussion', "
                ."'accepted', 'expired', 'cancelled'))",
            );

            DB::statement('ALTER TABLE quotations DROP CONSTRAINT IF EXISTS quotations_status_check');
            DB::statement(
                'ALTER TABLE quotations ADD CONSTRAINT quotations_status_check '
                ."CHECK (status IN ('pending_review', 'quotation_ready', 'under_discussion', "
                ."'accepted', 'expired', 'cancelled'))",
            );
            DB::statement("ALTER TABLE quotations ALTER COLUMN status SET DEFAULT 'pending_review'");
        }

        DB::table('quotations')
            ->whereIn('status', ['draft', 'submitted', 'under_review'])
            ->update(['status' => 'pending_review']);

        DB::table('quotation_status_histories')
            ->whereIn('status', ['draft', 'submitted', 'under_review'])
            ->update(['status' => 'pending_review']);

        Schema::table('quotations', function (Blueprint $table): void {
            $table->dropIndex(['status', 'assigned_admin_id']);
            $table->dropIndex(['customer_profile_id', 'status']);
            $table->dropConstrainedForeignId('assigned_admin_id');
            $table->dropColumn([
                'requirements',
                'description',
                'preferred_timing',
                'quantity_hint',
                'submitted_at',
            ]);
        });
    }
};
