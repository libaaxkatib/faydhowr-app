<?php

namespace App\Support\Hr\Payroll;

use App\Enums\AttendanceStatus;
use App\Enums\LeaveType;
use App\Enums\WorkAssignmentStatus;
use App\Models\ClientCompany;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeLeave;
use App\Models\EmployeePenalty;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollCorrection;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Which COMPANY a payroll input belongs to (owner decision 2026-11-03: each company owns its own payroll). A penalty, an advance, an unpaid leave, an
 * absence mark and an overpayment recovery are charged to ONE company, never to the person's first payroll line and never spread over several.
 *
 * Company keys are the company id as a string, or "none" for the assignments that name no company (the Fayadhowr office).
 *
 * For a NEW record the company is resolved from where the person works on the dates concerned:
 *  - exactly one company -> that company, automatically;
 *  - several companies   -> the person recording it MUST choose (422 COMPANY_REQUIRED), the choice must be one of them (COMPANY_NOT_ASSIGNED);
 *  - no assignment       -> no company (as before).
 * Nothing is guessed. A record from before this rule has no company ("legacy"): the payroll charges it only when it is unambiguous - the person
 * works for exactly one company that month - and otherwise reports it and charges it nowhere (see PayrollRunCalculator).
 */
final class InputOwnership
{
    public const NONE = 'none';

    public static function key(?int $companyId): string
    {
        return $companyId === null ? self::NONE : (string) $companyId;
    }

    /** The company of a key: null for "none". */
    public static function companyOf(string $key): ?int
    {
        return $key === self::NONE ? null : (int) $key;
    }

    /** The company key an assignment's payroll line belongs to. */
    public static function lineKey(EmployeeWorkAssignment $assignment): string
    {
        $company = $assignment->clientCompany ?? $assignment->workLocation?->clientCompany;

        return self::key($company?->id);
    }

    /**
     * The company keys of the person's assignments (not cancelled) that overlap the dates.
     *
     * @return list<string>
     */
    public static function keysOn(int $employeeId, string $from, string $to): array
    {
        $ids = EmployeeWorkAssignment::query()
            ->where('employee_id', $employeeId)
            ->where('status', '!=', WorkAssignmentStatus::Cancelled)
            ->whereDate('start_date', '<=', $to)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $from))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $keys = array_values(array_unique(array_map(fn (?int $company): string => self::key($company), PayrollSections::companyIdsByAssignment($ids))));
        sort($keys);

        return $keys;
    }

    /**
     * The company a NEW record belongs to.
     *
     * @param  bool  $provided  the request named a company (a null then means "no company": the office)
     * @param  int|null  $requested  the company named by the request
     *
     * @throws HttpResponseException
     */
    public static function resolve(int $employeeId, string $from, string $to, bool $provided, ?int $requested): ?int
    {
        $keys = self::keysOn($employeeId, $from, $to);

        if ($provided) {
            if ($keys !== [] && ! in_array(self::key($requested), $keys, true)) {
                self::fail('This employee does not work for that company on these dates. Choose one of: '.self::names($keys).'.', 'COMPANY_NOT_ASSIGNED', $keys);
            }

            return $requested;
        }

        return match (true) {
            $keys === [] => null,
            count($keys) === 1 => self::companyOf($keys[0]),
            default => self::fail('This employee works for more than one company on these dates ('.self::names($keys).'). Choose the company this belongs to: each company has its own payroll.', 'COMPANY_REQUIRED', $keys),
        };
    }

    /**
     * Records that name no company although their employee works for several that month, for the given employees: the payroll charges them to no
     * company (nothing is guessed), so a company that has such an employee must not be approved or finalized until HR assigns them.
     *
     * @param  list<int>  $employeeIds
     * @return list<array{employee_id: int, kind: string, id: int}>
     */
    public static function unownedFor(string $period, array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $first = $period.'-01';
        $last = CarbonImmutable::parse($first)->endOfMonth()->toDateString();

        // Only employees with more than one assignment in the month can be ambiguous.
        $shared = EmployeeWorkAssignment::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('status', '!=', WorkAssignmentStatus::Cancelled)
            ->whereDate('start_date', '<=', $last)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $first))
            ->groupBy('employee_id')->havingRaw('COUNT(*) > 1')
            ->pluck('employee_id')->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => count(self::keysOn($id, $first, $last)) > 1)
            ->values()->all();

        if ($shared === []) {
            return [];
        }

        $found = [];
        $collect = function (string $kind, $rows) use (&$found): void {
            foreach ($rows as $row) {
                $found[] = ['employee_id' => (int) $row->employee_id, 'kind' => $kind, 'id' => (int) $row->id];
            }
        };

        $collect('penalty', EmployeePenalty::query()->whereIn('employee_id', $shared)->where('payroll_period', $period)->whereNull('client_company_id')->get(['id', 'employee_id']));
        $collect('advance', EmployeeAdvance::query()->whereIn('employee_id', $shared)->where('payroll_period', $period)->whereNull('client_company_id')->get(['id', 'employee_id']));
        $collect('unpaid_leave', EmployeeLeave::query()->whereIn('employee_id', $shared)->where('leave_type', LeaveType::Unpaid)->where('approval_status', 'approved')->whereNull('client_company_id')
            ->whereDate('start_date', '<=', $last)->whereDate('end_date', '>=', $first)->get(['id', 'employee_id']));
        $collect('absence', EmployeeAttendance::query()->whereIn('employee_id', $shared)->where('status', AttendanceStatus::Absent)->whereNull('client_company_id')
            ->whereDate('date', '>=', $first)->whereDate('date', '<=', $last)->get(['id', 'employee_id']));
        $collect('overpayment_recovery', PayrollCorrection::query()->whereIn('employee_id', $shared)->where('status', 'open')->where('outstanding_cents', '>', 0)->where('period', '<', $period)->whereNull('client_company_id')->get(['id', 'employee_id']));

        return $found;
    }

    /** @param  list<string>  $keys */
    private static function names(array $keys): string
    {
        $ids = array_values(array_filter(array_map(fn (string $key): ?int => self::companyOf($key), $keys)));
        $names = ClientCompany::query()->whereIn('id', $ids)->pluck('name', 'id');

        return implode(', ', array_map(fn (string $key): string => $key === self::NONE ? 'no company (office)' : (string) ($names[(int) $key] ?? '#'.$key), $keys));
    }

    /** @param  list<string>  $keys */
    private static function fail(string $message, string $code, array $keys): never
    {
        $ids = array_values(array_filter(array_map(fn (string $key): ?int => self::companyOf($key), $keys)));
        $names = ClientCompany::query()->whereIn('id', $ids)->pluck('name', 'id');

        throw new HttpResponseException(ApiResponse::error($message, $code, 422, [
            'client_company_id' => [$message],
            'companies' => array_map(fn (string $key): array => ['id' => self::companyOf($key), 'name' => $key === self::NONE ? null : ($names[(int) $key] ?? null)], $keys),
        ]));
    }
}
