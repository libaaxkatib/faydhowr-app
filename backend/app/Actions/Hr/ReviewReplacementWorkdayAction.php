<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\TemporaryReplacement;
use App\Models\TemporaryReplacementWorkday;
use App\Support\ApiResponse;
use App\Support\Hr\Payroll\PayrollPeriodLock;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Verifies or voids a recorded workday. Verification is what makes a day
 * chargeable. A day is never deleted: voiding keeps the row with who, when and
 * why, and frees the date so a corrected entry can be recorded. Neither action
 * is allowed once the day's payroll month is approved or finalized - that
 * needs a payroll adjustment instead.
 */
class ReviewReplacementWorkdayAction
{
    public function __construct(private readonly PayrollPeriodLock $lock = new PayrollPeriodLock) {}

    public function verify(TemporaryReplacementWorkday $workday, Admin $actor): TemporaryReplacementWorkday
    {
        if ($workday->status !== TemporaryReplacementWorkday::STATUS_RECORDED) {
            $this->fail("Only a recorded workday can be verified (this one is {$workday->status}).", 'WORKDAY_NOT_VERIFIABLE');
        }

        $this->lock->assertDateEditable($workday->work_date->toDateString(), 'This workday');

        $workday->update(['status' => TemporaryReplacementWorkday::STATUS_VERIFIED, 'verified_at' => now(), 'verified_by' => $actor->id]);

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: "Replacement workday {$workday->work_date->toDateString()} verified for temporary replacement #{$workday->temporary_replacement_id}.",
            entityType: TemporaryReplacement::class,
            entityId: $workday->temporary_replacement_id,
        ));

        return $workday;
    }

    public function void(TemporaryReplacementWorkday $workday, string $reason, Admin $actor): TemporaryReplacementWorkday
    {
        if ($workday->status === TemporaryReplacementWorkday::STATUS_VOID) {
            $this->fail('This workday is already void.', 'WORKDAY_ALREADY_VOID');
        }

        $this->lock->assertDateEditable($workday->work_date->toDateString(), 'This workday');

        $workday->update([
            'status' => TemporaryReplacementWorkday::STATUS_VOID,
            'voided_at' => now(),
            'voided_by' => $actor->id,
            'void_reason' => $reason,
        ]);

        event(AuditEvent::record(
            action: AuditAction::Cancel,
            admin: $actor,
            description: "Replacement workday {$workday->work_date->toDateString()} voided for temporary replacement #{$workday->temporary_replacement_id}.",
            entityType: TemporaryReplacement::class,
            entityId: $workday->temporary_replacement_id,
            metadata: ['reason' => $reason],
        ));

        return $workday;
    }

    private function fail(string $message, string $code): never
    {
        throw new HttpResponseException(ApiResponse::error($message, $code, 422));
    }
}
