<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Settings backup snapshots, stored in the database rather than on disk
     * so they survive redeploys/restarts on hosts without a persistent
     * filesystem (e.g. Railway's ephemeral container disk). Additive-only:
     * no existing table is touched.
     *
     * Snapshots are tiny (settings + branches metadata as JSON, currently a
     * few hundred bytes each) and this reuses the already-provisioned
     * Postgres database rather than any new storage resource.
     */
    public function up(): void
    {
        Schema::create('settings_backups', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->json('snapshot');
            $table->unsignedInteger('size_bytes');
            $table->string('created_by')->nullable();
            $table->timestamp('created_at');

            $table->index(['created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings_backups');
    }
};
