<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Legacy migration (Sprint 28 — final): every pre-existing quotation
     * automatically receives an immutable Revision 1 carrying its current
     * pricing (`source = system_migration`), and `latest_revision_id` is set.
     * Quotation numbers and history are preserved; no manual steps.
     */
    public function up(): void
    {
        $legacyQuotations = DB::table('quotations')
            ->whereNull('latest_revision_id')
            ->whereNotIn('status', ['draft'])
            ->orderBy('id')
            ->get(['id', 'subtotal', 'discount_amount', 'tax_amount', 'total_amount', 'valid_until', 'created_at']);

        foreach ($legacyQuotations as $quotation) {
            $revisionId = DB::table('quotation_revisions')->insertGetId([
                'quotation_id' => $quotation->id,
                'version_number' => 1,
                'source' => 'system_migration',
                'subtotal_amount' => $quotation->subtotal,
                'discount_amount' => $quotation->discount_amount,
                'tax_amount' => $quotation->tax_amount,
                'total_amount' => $quotation->total_amount,
                'valid_until' => $quotation->valid_until ?? $quotation->created_at ?? now(),
                'terms' => null,
                'notes' => null,
                'issued_by_admin_id' => null,
                'created_at' => $quotation->created_at ?? now(),
            ]);

            DB::table('quotations')
                ->where('id', $quotation->id)
                ->update(['latest_revision_id' => $revisionId]);
        }
    }

    public function down(): void
    {
        DB::table('quotations')
            ->whereIn(
                'latest_revision_id',
                DB::table('quotation_revisions')->where('source', 'system_migration')->pluck('id'),
            )
            ->update(['latest_revision_id' => null]);

        DB::table('quotation_revisions')->where('source', 'system_migration')->delete();
    }
};
