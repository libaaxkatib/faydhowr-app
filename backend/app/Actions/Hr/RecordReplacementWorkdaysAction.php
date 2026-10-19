<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\EmployeeStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\TemporaryReplacement;
use App\Models\TemporaryReplacementWorkday;
use App\Support\ApiResponse;
use App\Support\Hr\Payroll\PayrollPeriodLock;
use App\Support\Hr\Payroll\SalaryTimeline;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Records the days a Temporary employee ACTUALLY worked covering a Permanent
 * employee's position. Only days entered here (and verified) are ever charged.
 * All dates are validated first and saved together, so a bad date saves
 * nothing. A date can be charged once per covered assignment and once per
 * Temporary employee (also enforced by partial unique indexes), cannot be in
 * the future, must lie inside the replacement window, and must fall in a
 * payroll month that is still open.
 */
class RecordReplacementWorkdaysAction
{
    public function __construct(private readonly PayrollPeriodLock $lock = new PayrollPeriodLock) {}

    /**
     * @param  list<string>  $dates  Y-m-d
     * @return Collection<int, TemporaryReplacementWorkday>
     */
    public function handle(TemporaryReplacement $replacement, array $dates, bool $verify, ?string $notes, Admin $actor): Collection
    {
        $replacement->loadMissing(['workAssignment', 'replacementEmployee']);
        $assignment = $replacement->workAssignment;

        if ($replacement->replacementEmployee->status !== EmployeeStatus::Active) {
            $this->fail('The Temporary employee must be Active to record workdays.', 'EMPLOYEE_NOT_ACTIVE');
        }

        $dates = collect($dates)->map(fn ($d) => CarbonImmutable::parse($d)->toDateString())->unique()->sort()->values();

        if ($dates->isEmpty()) {
            $this->fail('At least one workday date is required.', 'WORKDAY_DATES_REQUIRED');
        }

        try {
            $salary = new SalaryTimeline($assignment->monthlySalaryPeriods());
        } catch (\RuntimeException $e) {
            $this->fail($e->getMessage(), 'PAYROLL_UNSUPPORTED_SALARY');
        }
        $today = CarbonImmutable::now()->startOfDay();

        foreach ($dates as $date) {
            $day = CarbonImmutable::parse($date);

            if ($day->gt($today)) {
                $this->fail("{$date} is in the future; only days actually worked can be recorded.", 'WORKDAY_IN_FUTURE');
            }

            if ($day->lt($replacement->start_date) || ($replacement->end_date !== null && $day->gt($replacement->end_date))) {
                $this->fail("{$date} is outside the replacement period.", 'WORKDAY_OUTSIDE_REPLACEMENT_WINDOW');
            }

            if ($day->lt($assignment->start_date) || ($assignment->end_date !== null && $day->gt($assignment->end_date))) {
                $this->fail("{$date} is outside the covered employee's assignment dates.", 'WORKDAY_OUTSIDE_ASSIGNMENT');
            }

            if ($salary->monthlyCentsOn($day) === null) {
                $this->fail("No salary is defined for the covered employee on {$date}; the cost cannot be calculated.", 'WORKDAY_NO_PERMANENT_SALARY');
            }

            $this->lock->assertDateEditable($date, "Workday {$date}");

            $live = TemporaryReplacementWorkday::query()->where('status', '!=', TemporaryReplacementWorkday::STATUS_VOID)->whereDate('work_date', $date);

            if ((clone $live)->where('work_assignment_id', $assignment->id)->exists()) {
                $this->fail("{$date} is already charged for this covered position (by this or another replacement).", 'WORKDAY_ALREADY_CHARGED_FOR_POSITION');
            }

            if ((clone $live)->where('replacement_employee_id', $replacement->replacement_employee_id)->exists()) {
                $this->fail("The Temporary employee is already recorded as working on {$date}.", 'EMPLOYEE_ALREADY_RECORDED_THAT_DAY');
            }
        }

        return DB::transaction(function () use ($replacement, $assignment, $dates, $verify, $notes, $actor) {
            $created = $dates->map(fn (string $date) => TemporaryReplacementWorkday::query()->create([
                'temporary_replacement_id' => $replacement->id,
                'work_assignment_id' => $assignment->id,
                'replacement_employee_id' => $replacement->replacement_employee_id,
                'work_date' => $date,
                'status' => $verify ? TemporaryReplacementWorkday::STATUS_VERIFIED : TemporaryReplacementWorkday::STATUS_RECORDED,
                'notes' => $notes,
                'recorded_by' => $actor->id,
                'verified_at' => $verify ? now() : null,
                'verified_by' => $verify ? $actor->id : null,
            ]));

            event(AuditEvent::record(
                action: AuditAction::Create,
                admin: $actor,
                description: "{$created->count()} replacement workday(s) ".($verify ? 'recorded and verified' : 'recorded')." for temporary replacement #{$replacement->id}.",
                entityType: TemporaryReplacement::class,
                entityId: $replacement->id,
                metadata: ['dates' => $dates->all(), 'verified' => $verify],
            ));

            return $created;
        });
    }

    private function fail(string $message, string $code): never
    {
        throw new HttpResponseException(ApiResponse::error($message, $code, 422));
    }
}
