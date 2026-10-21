<?php

namespace App\Support\Hr;

use App\Enums\WorkAssignmentStatus;
use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Models\HrPolicy;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;

/**
 * "One employee, one client company at a time" - unless the Super Admin has switched on multiple-company assignments.
 *
 * While the policy is OFF (the default) an employee may not receive an assignment at a client company whose dates overlap an
 * assignment of theirs at a DIFFERENT client company. Ended assignments still count for the days they covered, so a successor
 * may start the day after the last day but not on or before it. The existing rules are untouched: several work locations of the
 * same company, and an office assignment next to a company one (which has its own explicit confirmation), stay as they are. While
 * ON, each assignment keeps its own company, position, dates, salary and currency. Nothing already recorded is changed by
 * switching the policy.
 */
final class ConcurrentAssignmentGuard
{
    public static function enabled(): bool
    {
        return HrPolicy::isEnabled(HrPolicy::MULTI_COMPANY_ASSIGNMENTS);
    }

    public static function assertAllowed(Employee $employee, ?int $companyId, CarbonImmutable $start, ?CarbonImmutable $end, ?int $ignoreAssignmentId = null): void
    {
        // An office assignment has no client company; it is governed by the existing office rules, not by this policy.
        if ($companyId === null || self::enabled()) {
            return;
        }

        $clash = EmployeeWorkAssignment::query()
            ->with(['clientCompany', 'workLocation'])
            ->where('employee_id', $employee->id)
            ->where('status', '!=', WorkAssignmentStatus::Cancelled)
            ->whereNotNull('client_company_id')
            ->where('client_company_id', '!=', $companyId)
            ->when($ignoreAssignmentId !== null, fn ($q) => $q->where('id', '!=', $ignoreAssignmentId))
            ->when($end !== null, fn ($q) => $q->whereDate('start_date', '<=', $end->toDateString()))
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $start->toDateString()))
            ->orderBy('start_date')
            ->first();

        if ($clash === null) {
            return;
        }

        $where = $clash->clientCompany?->name ?? $clash->workLocation?->name ?? 'another place';
        $until = $clash->end_date?->toDateString() ?? 'no end date';

        throw new HttpResponseException(ApiResponse::error(
            "Employee '{$employee->full_name}' ({$employee->employee_number}) already has an assignment at {$where} (from {$clash->start_date->toDateString()}, until {$until}) that overlaps these dates. An employee works for one company at a time unless the Super Admin allows multiple-company assignments.",
            'EMPLOYEE_ALREADY_ASSIGNED_ELSEWHERE',
            422,
        ));
    }

    /**
     * The employee's other live assignments at DIFFERENT client companies that overlap the given dates (what a second company would be added next to).
     *
     * @return Collection<int, EmployeeWorkAssignment>
     */
    public static function overlapsAtOtherCompanies(Employee $employee, ?int $companyId, CarbonImmutable $start, ?CarbonImmutable $end, ?int $ignoreAssignmentId = null): Collection
    {
        if ($companyId === null) {
            return collect();
        }

        return EmployeeWorkAssignment::query()
            ->with(['clientCompany', 'workLocation'])
            ->where('employee_id', $employee->id)
            ->where('status', '!=', WorkAssignmentStatus::Cancelled)
            ->whereNotNull('client_company_id')
            ->where('client_company_id', '!=', $companyId)
            ->when($ignoreAssignmentId !== null, fn ($q) => $q->where('id', '!=', $ignoreAssignmentId))
            ->when($end !== null, fn ($q) => $q->whereDate('start_date', '<=', $end->toDateString()))
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $start->toDateString()))
            ->orderBy('start_date')
            ->get();
    }

    /**
     * While the multi-company policy is ON, adding an employee to a company where they ALREADY work for another one needs an explicit
     * confirmation that names the employee, the current company (or companies) and the new company. The screens send
     * `confirm_additional_company`; without it nothing is saved and the answer carries what to show. It never changes the policy and never
     * applies when the policy is OFF (then the assignment is refused outright by assertAllowed).
     */
    public static function requireConfirmation(Employee $employee, ?int $companyId, string $newCompanyName, CarbonImmutable $start, ?CarbonImmutable $end, bool $confirmed): void
    {
        if ($companyId === null || $confirmed || ! self::enabled()) {
            return;
        }

        $others = self::overlapsAtOtherCompanies($employee, $companyId, $start, $end);

        if ($others->isEmpty()) {
            return;
        }

        $current = $others->map(fn (EmployeeWorkAssignment $a): string => $a->clientCompany?->name ?? 'another company')->unique()->values();

        throw new HttpResponseException(ApiResponse::error(
            "{$employee->full_name} ({$employee->employee_number}) is currently assigned to ".$current->implode(', ').". Do you want to assign them to {$newCompanyName} as well?",
            'ADDITIONAL_COMPANY_CONFIRMATION_REQUIRED',
            422,
            [
                'employee' => ['id' => $employee->id, 'employee_number' => $employee->employee_number, 'full_name' => $employee->full_name],
                'new_company' => $newCompanyName,
                'current_assignments' => $others->map(fn (EmployeeWorkAssignment $a): array => [
                    'company' => $a->clientCompany?->name,
                    'start_date' => $a->start_date->toDateString(),
                    'end_date' => $a->end_date?->toDateString(),
                ])->values()->all(),
            ],
        ));
    }
}
