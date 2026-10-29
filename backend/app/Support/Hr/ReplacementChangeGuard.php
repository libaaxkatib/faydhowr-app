<?php

namespace App\Support\Hr;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\TemporaryReplacement;
use App\Support\ApiResponse;
use App\Support\Hr\Payroll\ClosedPayrollCorrection;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Runs a change of a Temporary cover's dates so that it can never silently alter approved or finalized payroll.
 *
 *  - A change that adds or removes a day inside a payroll month that is approved or finalized is a correction of closed payroll: only a Super Admin may make
 *    it, with a written reason; anyone else is refused and nothing is changed. This holds even when the cover is not approved, because the Temporary's
 *    amount in that month's payment list is worked out from the cover itself.
 *  - Whatever the change does to the covered Permanent's net pay in a closed month is recorded as a payroll correction by ClosedPayrollCorrection (the
 *    closed run is never rewritten). A change that leaves every closed month as it was needs no correction.
 *  - A change that only reaches open months is allowed for whoever may edit covers.
 */
final class ReplacementChangeGuard
{
    public const MIN_REASON = 10;

    public function __construct(private readonly ClosedPayrollCorrection $corrections = new ClosedPayrollCorrection) {}

    /**
     * @template T
     *
     * @param  array<string, mixed>  $impact  ReplacementChangeImpact::forChange
     * @param  array<string, mixed>  $context
     * @param  Closure(): T  $change
     * @return T
     */
    public function run(Admin $actor, TemporaryReplacement $replacement, array $impact, ?string $reason, string $action, array $context, Closure $change): mixed
    {
        $reason = $reason === null ? null : trim($reason);

        if ($impact['changes_locked_period']) {
            $periods = implode(', ', $impact['locked_periods_changed']);

            if ($actor->role !== AdminRole::SuperAdmin) {
                throw new HttpResponseException(ApiResponse::error(
                    "This change adds or removes covered days in {$periods}, which is approved or finalized payroll. Only a Super Admin can correct closed payroll. Nothing was changed.",
                    'PAYROLL_CORRECTION_SUPER_ADMIN_ONLY',
                    403,
                    ['affected_periods' => $impact['locked_periods_changed']],
                ));
            }

            if ($reason === null || mb_strlen($reason) < self::MIN_REASON) {
                throw new HttpResponseException(ApiResponse::error(
                    "This change reaches {$periods}, which is approved or finalized payroll. Enter the reason for the correction (at least ".self::MIN_REASON.' characters). Nothing was changed.',
                    'CORRECTION_REASON_REQUIRED',
                    422,
                    ['correction_reason' => ['A correction reason is required.'], 'affected_periods' => $impact['locked_periods_changed']],
                ));
            }
        }

        $assignment = $replacement->workAssignment()->firstOrFail();
        $earliest = min($impact['old']['start_date'], $impact['new']['start_date']);

        return $this->corrections->run(
            $actor,
            $action,
            [(int) $assignment->employee_id],
            $earliest,
            $reason,
            [...$context, 'assignment_id' => $assignment->id, 'temporary_replacement_id' => $replacement->id],
            $change,
        );
    }
}
