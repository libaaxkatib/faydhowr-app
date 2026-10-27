<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\TemporaryReplacementStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\TemporaryReplacement;
use App\Support\ApiResponse;
use App\Support\Hr\CoverConflicts;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;

class EndTemporaryReplacementAction
{
    public function handle(TemporaryReplacement $replacement, string $endDate, ?string $notes, Admin $actor): TemporaryReplacement
    {
        if ($replacement->status !== TemporaryReplacementStatus::Active) {
            throw new HttpResponseException(
                ApiResponse::error(
                    'This temporary replacement has already ended.',
                    'TEMPORARY_REPLACEMENT_NOT_ACTIVE',
                    422,
                ),
            );
        }

        // The new last day must not run into another Temporary cover of the position or of the person (U12), nor into the person's own assignment (U1).
        // This cover is never compared with itself. Only a LATER last day can add days (a shorter cover can never create an overlap, so ending early is always allowed).
        $lastDay = CarbonImmutable::parse($endDate)->toDateString();

        if ($replacement->end_date !== null && $lastDay > $replacement->end_date->toDateString()) {
            CoverConflicts::assertFree($replacement->workAssignment()->firstOrFail(), $replacement->replacementEmployee()->firstOrFail(), $replacement->start_date->toDateString(), $lastDay, $replacement->id);
        }

        // Keeps the planned end date when the cover is ended earlier (docs/HR_PAYROLL_RULES.md N10).
        $replacement->endOn($endDate, $notes);

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: "Temporary replacement #{$replacement->id} coverage ended.",
            entityType: TemporaryReplacement::class,
            entityId: $replacement->id,
        ));

        return $replacement->load('workAssignment.employee', 'workAssignment.workLocation.clientCompany', 'workAssignment.clientCompany', 'replacementEmployee', 'createdBy', 'payments.paidBy');
    }
}
