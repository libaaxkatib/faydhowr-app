<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Owner decision 2026-11-02: any person in the HR system may be chosen as the successor of a Permanent position, not only a person on the Waiting List.
 * The new value `person` is recorded in assignment_successions.successor_type, so the PostgreSQL CHECK that listed only 'temporary' and 'waiting_list'
 * must allow it (SQLite never had the constraint). Existing rows are untouched and stay valid; nothing is written.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE assignment_successions DROP CONSTRAINT IF EXISTS assignment_successions_type_check');
        DB::statement("ALTER TABLE assignment_successions ADD CONSTRAINT assignment_successions_type_check CHECK (successor_type IN ('temporary', 'waiting_list', 'person'))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // NOT VALID: a rollback never fails because 'person' rows exist; it only stops new ones.
        DB::statement('ALTER TABLE assignment_successions DROP CONSTRAINT IF EXISTS assignment_successions_type_check');
        DB::statement("ALTER TABLE assignment_successions ADD CONSTRAINT assignment_successions_type_check CHECK (successor_type IN ('temporary', 'waiting_list')) NOT VALID");
    }
};
