<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\TemporaryReplacementStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\TemporaryReplacement;
use App\Support\ApiResponse;
use App\Support\Hr\CoverConflicts;
use App\Support\Hr\ReplacementChangeGuard;
use App\Support\Hr\ReplacementChangeImpact;
use App\Support\Hr\ReplacementVersion;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Ends a Temporary cover on its actual last day (docs/HR_PAYROLL_RULES.md N10). The last day is the day the cover really stopped; it is never filled in
 * for the user. A last day that is before the start is refused. A last day that LENGTHENS the cover (later than the end already recorded) adds days and
 * cost, so it is only accepted when the caller confirms it ($confirmExtension) - the screen shows the old and new dates, the added days and the new cost
 * first. A change that reaches an approved or finalized payroll month is a closed-payroll correction (ReplacementChangeGuard). Nothing else is touched:
 * not the covered assignment, not the salary, not a payment.
 */
class EndTemporaryReplacementAction
{
    public function __construct(
        private readonly ReplacementChangeImpact $impact = new ReplacementChangeImpact,
        private readonly ReplacementChangeGuard $guard = new ReplacementChangeGuard,
    ) {}

    public function handle(TemporaryReplacement $replacement, string $endDate, ?string $notes, Admin $actor, bool $confirmExtension = false, ?string $reason = null, ?string $version = null): TemporaryReplacement
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

        if ($version !== null && $version !== ReplacementVersion::of($replacement)) {
            throw new HttpResponseException(ApiResponse::error('This replacement was changed by someone else. Reload it and try again.', 'TEMPORARY_REPLACEMENT_CHANGED', 409));
        }

        $lastDay = CarbonImmutable::parse($endDate)->toDateString();

        if ($lastDay < $replacement->start_date->toDateString()) {
            throw new HttpResponseException(ApiResponse::error('The last day cannot be before the start date of the cover.', 'TEMPORARY_REPLACEMENT_END_BEFORE_START', 422, ['end_date' => ['The last day cannot be before the start date.']]));
        }

        $impact = $this->impact->forChange($replacement, ['end_date' => $lastDay], false);

        // The new last day must not run into another Temporary cover of the position or of the person (U12), nor into the person's own assignment (U1).
        // This cover is never compared with itself. Only a LATER last day can add days (a shorter cover can never create an overlap, so ending early is always allowed).
        if ($replacement->end_date !== null && $lastDay > $replacement->end_date->toDateString()) {
            CoverConflicts::assertFree($replacement->workAssignment()->firstOrFail(), $replacement->replacementEmployee()->firstOrFail(), $replacement->start_date->toDateString(), $lastDay, $replacement->id);
        }

        if (($impact['extends_end'] || $impact['days_added'] > 0) && ! $confirmExtension) {
            throw new HttpResponseException(ApiResponse::error(
                "This last day ({$lastDay}) is later than the end date recorded for the cover ({$impact['old']['end_date']}). It lengthens the cover and adds cost. Confirm the extension to continue. Nothing was changed.",
                'EXTENSION_CONFIRMATION_REQUIRED',
                422,
                ['old_end_date' => $impact['old']['end_date'], 'new_end_date' => $lastDay, 'days_added' => $impact['days_added']],
            ));
        }

        return $this->guard->run($actor, $replacement, $impact, $reason, 'cover_ended', ['old_end_date' => $impact['old']['end_date'], 'new_end_date' => $lastDay], function () use ($replacement, $lastDay, $notes, $actor, $impact, $reason) {
            return DB::transaction(function () use ($replacement, $lastDay, $notes, $actor, $impact, $reason) {
                $before = ['status' => $replacement->status->value, 'end_date' => $impact['old']['end_date'], 'planned_end_date' => $replacement->planned_end_date?->toDateString(), 'approved' => $replacement->approved_at !== null];

                // Keeps the planned end date when the cover is ended earlier (docs/HR_PAYROLL_RULES.md N10).
                $replacement->endOn($lastDay, $notes);

                // Days that are new are not covered by the approval that was given: an approval is cleared when days are added in months that are still open.
                $approvalCleared = false;

                if ($replacement->approved_at !== null && $impact['days_added'] > 0 && ! $impact['touches_locked_period']) {
                    $replacement->update(['approved_at' => null, 'approved_by' => null]);
                    $approvalCleared = true;
                }

                event(AuditEvent::record(
                    action: AuditAction::Update,
                    admin: $actor,
                    description: "Temporary replacement #{$replacement->id} coverage ended on {$lastDay}.",
                    entityType: TemporaryReplacement::class,
                    entityId: $replacement->id,
                    metadata: [
                        'event' => 'temporary_replacement_ended',
                        'before' => $before,
                        'after' => ['status' => 'ended', 'end_date' => $lastDay, 'planned_end_date' => $replacement->planned_end_date?->toDateString(), 'approved' => $replacement->approved_at !== null],
                        'extension' => $impact['extends_end'] || $impact['days_added'] > 0,
                        'days_added' => $impact['days_added'],
                        'days_removed' => $impact['days_removed'],
                        'approval_cleared' => $approvalCleared,
                        'locked_periods_changed' => $impact['locked_periods_changed'],
                        'reason' => $reason,
                    ],
                ));

                return $replacement->load('workAssignment.employee', 'workAssignment.workLocation.clientCompany', 'workAssignment.clientCompany', 'replacementEmployee', 'createdBy', 'payments.paidBy');
            });
        });
    }
}
