<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\TemporaryReplacement;
use App\Models\TemporaryReplacementPayment;

/**
 * A manual payment ledger (docs/HRM_MARKETING_SRS.md HR Phase 3, confirmed
 * scope decision): a row's existence IS the paid record - no pending/paid
 * state machine, no calendar-day auto-generation. HR logs each payment as
 * they actually make it. Allowed any time, including after coverage ends
 * (final settlement) - no status guard.
 */
class RecordTemporaryReplacementPaymentAction
{
    public function handle(TemporaryReplacement $replacement, array $data, Admin $actor): TemporaryReplacement
    {
        $payment = TemporaryReplacementPayment::query()->create([
            ...$data,
            'temporary_replacement_id' => $replacement->id,
            'paid_by' => $actor->id,
        ]);

        event(AuditEvent::record(
            action: AuditAction::Create,
            admin: $actor,
            description: "Payment of {$payment->amount} recorded for temporary replacement #{$replacement->id}.",
            entityType: TemporaryReplacement::class,
            entityId: $replacement->id,
            metadata: ['payment_id' => $payment->id, 'amount' => (string) $payment->amount],
        ));

        return $replacement->load('workAssignment.employee', 'workAssignment.workLocation.clientCompany', 'replacementEmployee', 'createdBy', 'payments.paidBy');
    }
}
