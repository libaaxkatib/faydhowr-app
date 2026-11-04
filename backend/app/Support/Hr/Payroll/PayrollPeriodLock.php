<?php

namespace App\Support\Hr\Payroll;

use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * A payroll month that has been approved or finalized is locked: its attendance, penalty, advance, leave and workday inputs may no longer
 * change. Assignment and salary changes that reach a closed month are handled by ClosedPayrollCorrection (Super Admin only, with a
 * reason, the closed month never rewritten), so a finalized month always stays reproducible.
 *
 * With company sections the lock is per COMPANY (owner decision 2026-11-03): an input is locked when the company it BELONGS TO has an approved or
 * finalized section for that month, never because some other company does. Every input now names its company (InputOwnership), and that owner
 * decides: an employee who works for Company A and Company B can still have a Company B input recorded while Company A is finalized, and the
 * reverse. A Temporary workday follows the covered position's assignment. A run without sections, or a month whose every company is approved /
 * finalized (the run itself is then approved / finalized), locks the whole month exactly as before.
 *
 * An input from before this rule has no owner company ("legacy"): it is then judged by where the employee works on those dates, and is locked when
 * ANY of those companies is protected (the safe side: it could change a protected company's pay).
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

    /** Locked for ONE assignment's company in that month (used where the covered position, not a person, is what changes). */
    public function isLockedForAssignment(string $period, int $assignmentId): bool
    {
        return $this->lockedFor($period, $period.'-01', $this->lastDay($period), null, $assignmentId, null);
    }

    /**
     * @param  string  $date  a calendar date (Y-m-d); its month is the payroll month
     * @param  int|null  $employeeId  the employee the input is about (used only when the input names no company)
     * @param  int|null  $assignmentId  the assignment it is about (a Temporary workday: the covered assignment)
     * @param  string|null  $ownerKey  the input's owner company key (InputOwnership::key); decides the lock when given
     */
    public function assertDateEditable(string $date, string $what, ?int $employeeId = null, ?int $assignmentId = null, ?string $ownerKey = null): void
    {
        $day = substr($date, 0, 10);

        $this->assert($what, substr($date, 0, 7), $this->lockedFor(substr($date, 0, 7), $day, $day, $employeeId, $assignmentId, $ownerKey));
    }

    /** A record kept for the whole month (a penalty or an advance). */
    public function assertMonthEditable(string $period, string $what, ?int $employeeId, ?string $ownerKey = null): void
    {
        $this->assert($what, $period, $this->lockedFor($period, $period.'-01', $this->lastDay($period), $employeeId, null, $ownerKey));
    }

    /** The days of a period of leave that fall in this month. */
    public function assertRangeEditable(string $month, string $from, string $to, string $what, ?int $employeeId, ?string $ownerKey = null): void
    {
        $period = substr($month, 0, 7);
        $start = max($from, $period.'-01');
        $end = min($to, $this->lastDay($period));

        $this->assert($what, $period, $this->lockedFor($period, $start, max($start, $end), $employeeId, null, $ownerKey));
    }

    private function assert(string $what, string $period, bool $locked): void
    {
        if ($locked) {
            throw new HttpResponseException(ApiResponse::error(
                "{$what} falls in payroll month {$period} which is approved or finalized. A Super Admin or an HR Manager can reopen that company's payroll for correction (with a reason), or record a payroll adjustment in a later month.",
                'PAYROLL_PERIOD_LOCKED',
                422,
            ));
        }
    }

    private function lastDay(string $period): string
    {
        return CarbonImmutable::parse($period.'-01')->endOfMonth()->toDateString();
    }

    private function lockedFor(string $period, string $from, string $to, ?int $employeeId, ?int $assignmentId, ?string $ownerKey): bool
    {
        $run = PayrollRun::query()->where('period', $period)->first(['id', 'status']);

        if ($run === null) {
            return false;
        }

        // The whole month: a run without sections, or one whose every company is approved / finalized.
        if (in_array($run->status, self::LOCKED_STATUSES, true)) {
            return true;
        }

        $locked = array_map(fn (?int $id): string => InputOwnership::key($id), PayrollSections::lockedCompanyIds($run));

        if ($locked === [] || ($employeeId === null && $assignmentId === null && $ownerKey === null)) {
            return false;
        }

        return array_intersect($locked, $ownerKey !== null ? [$ownerKey] : $this->companiesOf($run, $from, $to, $employeeId, $assignmentId)) !== [];
    }

    /**
     * Companies a legacy input (no owner company) or a covered-position input can affect.
     *
     * @return list<string> company keys
     */
    private function companiesOf(PayrollRun $run, string $from, string $to, ?int $employeeId, ?int $assignmentId): array
    {
        if ($assignmentId !== null) {
            return array_values(array_unique(array_map(fn (?int $id): string => InputOwnership::key($id), PayrollSections::companyIdsByAssignment([$assignmentId]))));
        }

        $keys = InputOwnership::keysOn((int) $employeeId, $from, $to);

        if ($keys !== []) {
            return $keys;
        }

        // No assignment covers the dates (a record for a month the person had no assignment in): fall back to where the person has a stored line.
        return PayrollItem::query()->where('payroll_run_id', $run->id)->where('employee_id', $employeeId)->whereNotNull('section_id')
            ->with('section:id,client_company_id')->get(['id', 'section_id'])
            ->map(fn (PayrollItem $item): string => InputOwnership::key($item->section?->client_company_id))
            ->unique()->values()->all();
    }
}
