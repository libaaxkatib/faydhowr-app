<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\TemporaryReplacementStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\TemporaryReplacement;
use App\Support\ApiResponse;
use App\Support\Hr\Facility\FacilityCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * The safe way to finish a cover whose end date has already passed ("Expired - Needs Closing"). It takes NO date: the status becomes Ended and the end
 * date stays exactly as it was recorded, so the covered days, the cost, the approval and every payroll figure are, by construction, unchanged. A cover that
 * has not expired, or that has no end date, cannot be closed this way (use End Coverage and type the last day).
 */
class CloseExpiredTemporaryReplacementAction
{
    public function handle(TemporaryReplacement $replacement, Admin $actor): TemporaryReplacement
    {
        return DB::transaction(function () use ($replacement, $actor) {
            $replacement->newQuery()->whereKey($replacement->id)->lockForUpdate()->first();
            $replacement->refresh();

            if ($replacement->status !== TemporaryReplacementStatus::Active) {
                throw new HttpResponseException(ApiResponse::error('This temporary replacement has already ended.', 'TEMPORARY_REPLACEMENT_NOT_ACTIVE', 422));
            }

            $today = FacilityCalendar::today()->toDateString();

            if ($replacement->end_date === null || $replacement->end_date->toDateString() >= $today) {
                throw new HttpResponseException(ApiResponse::error('This cover has not expired: it has no end date, or its end date has not passed yet. Use End Coverage and enter the last day.', 'TEMPORARY_REPLACEMENT_NOT_EXPIRED', 422));
            }

            $endDate = $replacement->end_date->toDateString();

            // The end date is written back unchanged: nothing about the days or the cost moves.
            $replacement->endOn($endDate);

            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: "Temporary replacement #{$replacement->id} closed after its end date ({$endDate}); the dates and the cost were not changed.",
                entityType: TemporaryReplacement::class,
                entityId: $replacement->id,
                metadata: [
                    'event' => 'temporary_replacement_closed_expired',
                    'before' => ['status' => 'active', 'end_date' => $endDate],
                    'after' => ['status' => 'ended', 'end_date' => $endDate],
                    'days_overdue' => (int) abs(CarbonImmutable::parse($endDate)->diffInDays(CarbonImmutable::parse($today))),
                ],
            ));

            return $replacement->load('workAssignment.employee', 'workAssignment.workLocation.clientCompany', 'workAssignment.clientCompany', 'replacementEmployee', 'createdBy', 'payments.paidBy');
        });
    }
}
