<?php

namespace App\Actions\Marketing;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\CommissionRate;

/**
 * Stores WHAT rate is configured (e.g. "5% effective 2026-09-01"), never a
 * calculation formula or a computed commission amount — the formula itself
 * is explicitly TBD per docs/HRM_MARKETING_SRS.md §21/§39.
 */
class CreateCommissionRateAction
{
    public function handle(array $data, Admin $actor): CommissionRate
    {
        $rate = CommissionRate::query()->create([
            ...$data,
            'created_by' => $actor->id,
        ]);

        event(AuditEvent::record(
            action: AuditAction::Create,
            admin: $actor,
            description: 'Commission rate configured.',
            entityType: CommissionRate::class,
            entityId: $rate->id,
            metadata: $data,
        ));

        return $rate->load('admin');
    }
}
