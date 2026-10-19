<?php

namespace App\Support\Hr;

use App\Enums\WorkAssignmentStatus;
use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Models\HrPolicy;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;

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
}
