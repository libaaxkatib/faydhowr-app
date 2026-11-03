<?php

namespace App\Support\Hr\Payroll;

use App\Enums\WorkAssignmentStatus;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * A payroll month that has been approved or finalized is locked: its attendance, penalty, leave and workday inputs may no longer
 * change. Assignment and salary changes that reach a closed month are handled by ClosedPayrollCorrection (Super Admin only, with a
 * reason, the closed month never rewritten), so a finalized month always stays reproducible.
 *
 * With company sections the lock is per COMPANY: an input is locked when the company it belongs to has an approved or finalized section for that
 * month, never because some other company does. The company of an input is found from the employee's assignments on that date (the covered
 * position's assignment for a Temporary workday). A run without sections, or a month whose every company is approved / finalized (the run itself
 * is then approved / finalized), locks the whole month exactly as before.
 *
 * An employee who works for two companies on the same date is locked when EITHER company is protected (the safe side: an input could change a
 * protected company's pay). A penalty or advance (recorded for the month, taken once on the person's first payroll line) follows that first line.
 */
final class PayrollPeriodLock
{
    public const LOCKED_STATUSES = ['approved', 'finalized'];

    /** The whole month is locked (run level: no sections, or every company is approved / finalized). */
    public function isLocked(string $period): bool
    {
        return PayrollRun::query()
            ->where('period', $period)
            ->whereIn('status', self::LOCKED_STATUSES)
            ->exists();
    }

    /**
     * Locked for ONE assignment's company in that month (used where the covered position, not a person, is what changes).
     */
    public function isLockedForAssignment(string $period, int $assignmentId): bool
    {
        return $this->lockedFor($period, $period.'-01', $this->lastDay($period), null, $assignmentId, false);
    }

    /**
     * @param  string  $date  a calendar date (Y-m-d); its month is the payroll month
     * @param  int|null  $employeeId  the employee the input is about
     * @param  int|null  $assignmentId  the assignment it is about (a Temporary workday: the covered assignment)
     */
    public function assertDateEditable(string $date, string $what, ?int $employeeId = null, ?int $assignmentId = null): void
    {
        $day = substr($date, 0, 10);

        $this->assert($what, substr($date, 0, 7), $this->lockedFor(substr($date, 0, 7), $day, $day, $employeeId, $assignmentId, false));
    }

    /**
     * A record kept for the whole month (a penalty or an advance): taken once, on the employee's first payroll line.
     */
    public function assertMonthEditable(string $period, string $what, ?int $employeeId): void
    {
        $this->assert($what, $period, $this->lockedFor($period, $period.'-01', $this->lastDay($period), $employeeId, null, true));
    }

    /**
     * The days of a period of leave that fall in this month.
     */
    public function assertRangeEditable(string $month, string $from, string $to, string $what, ?int $employeeId): void
    {
        $period = substr($month, 0, 7);
        $start = max($from, $period.'-01');
        $end = min($to, $this->lastDay($period));

        $this->assert($what, $period, $this->lockedFor($period, $start, max($start, $end), $employeeId, null, false));
    }

    private function assert(string $what, string $period, bool $locked): void
    {
        if ($locked) {
            throw new HttpResponseException(ApiResponse::error(
                "{$what} falls in payroll month {$period} which is approved or finalized. Record a payroll adjustment instead.",
                'PAYROLL_PERIOD_LOCKED',
                422,
            ));
        }
    }

    private function lastDay(string $period): string
    {
        return CarbonImmutable::parse($period.'-01')->endOfMonth()->toDateString();
    }

    private function lockedFor(string $period, string $from, string $to, ?int $employeeId, ?int $assignmentId, bool $firstLineOnly): bool
    {
        $run = PayrollRun::query()->where('period', $period)->first(['id', 'status']);

        if ($run === null) {
            return false;
        }

        // The whole month: a run without sections, or one whose every company is approved / finalized.
        if (in_array($run->status, self::LOCKED_STATUSES, true)) {
            return true;
        }

        $locked = array_map(fn (?int $id): string => $id === null ? 'none' : (string) $id, PayrollSections::lockedCompanyIds($run));

        if ($locked === [] || ($employeeId === null && $assignmentId === null)) {
            return false;
        }

        return array_intersect($locked, $this->companiesOf($run, $from, $to, $employeeId, $assignmentId, $firstLineOnly)) !== [];
    }

    /**
     * @return list<string> company ids ("none" = no company) the input can affect
     */
    private function companiesOf(PayrollRun $run, string $from, string $to, ?int $employeeId, ?int $assignmentId, bool $firstLineOnly): array
    {
        if ($assignmentId !== null) {
            $ids = [$assignmentId];
        } else {
            $ids = EmployeeWorkAssignment::query()
                ->where('employee_id', $employeeId)
                ->where('status', '!=', WorkAssignmentStatus::Cancelled)
                ->whereDate('start_date', '<=', $to)
                ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $from))
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            if ($firstLineOnly) {
                $ids = array_slice($ids, 0, 1);
            }
        }

        if ($ids !== []) {
            return array_values(array_unique(array_map(fn (?int $id): string => $id === null ? 'none' : (string) $id, PayrollSections::companyIdsByAssignment($ids))));
        }

        // No assignment covers the date (a record for a month the person had no assignment in): fall back to where the person has a stored line.
        return PayrollItem::query()->where('payroll_run_id', $run->id)->where('employee_id', $employeeId)->whereNotNull('section_id')
            ->with('section:id,client_company_id')->get(['id', 'section_id'])
            ->map(fn (PayrollItem $item): string => $item->section?->client_company_id === null ? 'none' : (string) $item->section->client_company_id)
            ->unique()->values()->all();
    }
}
