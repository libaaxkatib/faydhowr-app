<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transition guard (Step 4): a Super Admin may override the normal employee lifecycle only with a
 * written reason. The reason lives on the status-history row it justifies, next to the existing
 * from/to status, the acting admin (changed_by) and the timestamp (created_at). `source` records
 * which workflow made the change and `import_batch_id` links a change made by an import.
 * Purely additive: existing rows keep NULL in all three columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_status_histories', function (Blueprint $table): void {
            $table->text('override_reason')->nullable()->after('note');
            $table->string('source', 40)->nullable()->after('override_reason');
            $table->unsignedBigInteger('import_batch_id')->nullable()->after('source');
            $table->index('import_batch_id');
        });
    }

    public function down(): void
    {
        Schema::table('employee_status_histories', function (Blueprint $table): void {
            $table->dropIndex(['import_batch_id']);
            $table->dropColumn(['override_reason', 'source', 'import_batch_id']);
        });
    }
};
