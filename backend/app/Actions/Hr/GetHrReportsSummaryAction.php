<?php

namespace App\Actions\Hr;

use App\Enums\EmployeeStatus;
use App\Enums\WorkAssignmentStatus;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeCategory;
use App\Models\EmployeePayment;
use App\Models\EmployeePenalty;
use App\Models\EmployeePerformanceReview;
use App\Models\EmployeeSeparation;
use App\Models\EmployeeStatusHistory;
use App\Models\EmployeeWorkAssignment;
use App\Models\TemporaryReplacement;
use App\Models\WorkforceRequest;
use App\Models\WorkLocation;
use Carbon\CarbonImmutable;

/**
 * Covers docs/HRM_MARKETING_SRS.md §29's report list (employee list is the
 * existing GET /hr/employees endpoint; this covers the aggregate reports:
 * status/category/department breakdowns and a date-ranged registrations
 * count), plus the Work Assignments requirement's §8 workplace/salary
 * reporting. Real Eloquent aggregation only — no PDF/Excel export in this
 * pass, and no salary formula/calculation invented.
 */
class GetHrReportsSummaryAction
{
    public function handle(?string $from, ?string $to, array $filters = []): array
    {
        $from ??= CarbonImmutable::now()->subDays(30)->toDateString();
        $to ??= CarbonImmutable::now()->toDateString();

        $statusCounts = Employee::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $categoryCounts = EmployeeCategory::query()
            ->withCount('employees')
            ->orderBy('name')
            ->get()
            ->map(fn (EmployeeCategory $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'total' => $category->employees_count,
            ]);

        $departmentCounts = Department::query()
            ->withCount('employees')
            ->orderBy('name')
            ->get()
            ->map(fn (Department $department) => [
                'id' => $department->id,
                'name' => $department->name,
                'total' => $department->employees_count,
            ]);

        $registrationsInRange = Employee::query()
            ->whereBetween('application_date', [$from, $to])
            ->count();

        $pipelineCounts = Employee::query()
            ->whereNotNull('pipeline_stage')
            ->selectRaw('pipeline_stage, count(*) as total')
            ->groupBy('pipeline_stage')
            ->pluck('total', 'pipeline_stage');

        $assignmentQuery = EmployeeWorkAssignment::query();

        if (! empty($filters['client_company_id'])) {
            $assignmentQuery->whereHas(
                'workLocation',
                fn ($q) => $q->where('client_company_id', $filters['client_company_id']),
            );
        }

        if (! empty($filters['work_location_id'])) {
            $assignmentQuery->where('employee_work_assignments.work_location_id', $filters['work_location_id']);
        }

        if (! empty($filters['assignment_status'])) {
            $assignmentQuery->where('employee_work_assignments.status', $filters['assignment_status']);
        } else {
            $assignmentQuery->where('employee_work_assignments.status', WorkAssignmentStatus::Active);
        }

        $assignmentCountsByLocation = (clone $assignmentQuery)
            ->selectRaw('employee_work_assignments.work_location_id, count(*) as total')
            ->groupBy('employee_work_assignments.work_location_id')
            ->get()
            ->keyBy('work_location_id');

        $workplaceBreakdown = WorkLocation::query()
            ->with('clientCompany')
            ->orderBy('name')
            ->get()
            ->map(fn (WorkLocation $location) => [
                'id' => $location->id,
                'name' => $location->name,
                'location_type' => $location->location_type->value,
                'client_company_name' => $location->clientCompany?->name,
                'capacity' => $location->capacity,
                'total' => (int) ($assignmentCountsByLocation[$location->id]->total ?? 0),
            ]);

        $companySalaryTotals = (clone $assignmentQuery)
            ->join('work_locations', 'work_locations.id', '=', 'employee_work_assignments.work_location_id')
            ->join('client_companies', 'client_companies.id', '=', 'work_locations.client_company_id')
            ->selectRaw('client_companies.id, client_companies.name, sum(employee_work_assignments.salary_amount) as total_salary, count(*) as total_assignments')
            ->groupBy('client_companies.id', 'client_companies.name')
            ->orderBy('client_companies.name')
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'name' => $row->name,
                'total_salary' => $row->total_salary,
                'total_assignments' => (int) $row->total_assignments,
            ]);

        $workforceRequestBreakdown = WorkforceRequest::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $paymentTotalsByDepartment = EmployeePayment::query()
            ->join('employees', 'employees.id', '=', 'employee_payments.employee_id')
            ->join('departments', 'departments.id', '=', 'employees.department_id')
            ->whereBetween('employee_payments.payment_date', [$from, $to])
            ->selectRaw('departments.id, departments.name, sum(employee_payments.amount) as total_paid, count(*) as total_payments')
            ->groupBy('departments.id', 'departments.name')
            ->orderBy('departments.name')
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'name' => $row->name,
                'total_paid' => $row->total_paid,
                'total_payments' => (int) $row->total_payments,
            ]);

        $penaltyTotalsByDepartment = EmployeePenalty::query()
            ->join('employees', 'employees.id', '=', 'employee_penalties.employee_id')
            ->join('departments', 'departments.id', '=', 'employees.department_id')
            ->whereBetween('employee_penalties.penalty_date', [$from, $to])
            ->selectRaw('departments.id, departments.name, sum(employee_penalties.deduction_amount) as total_deducted, count(*) as total_penalties')
            ->groupBy('departments.id', 'departments.name')
            ->orderBy('departments.name')
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'name' => $row->name,
                'total_deducted' => $row->total_deducted,
                'total_penalties' => (int) $row->total_penalties,
            ]);

        $advanceTotalsByDepartment = EmployeeAdvance::query()
            ->join('employees', 'employees.id', '=', 'employee_advances.employee_id')
            ->join('departments', 'departments.id', '=', 'employees.department_id')
            ->whereBetween('employee_advances.advance_date', [$from, $to])
            ->selectRaw('departments.id, departments.name, sum(employee_advances.amount) as total_advanced, count(*) as total_advances')
            ->groupBy('departments.id', 'departments.name')
            ->orderBy('departments.name')
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'name' => $row->name,
                'total_advanced' => $row->total_advanced,
                'total_advances' => (int) $row->total_advances,
            ]);

        // --- Waiting Analytics (docs/HRM_MARKETING_SRS.md HR Phase 6) ---
        $waitingByCategory = Employee::query()
            ->where('status', EmployeeStatus::Waiting)
            ->join('employee_categories', 'employee_categories.id', '=', 'employees.employee_category_id')
            ->selectRaw('employee_categories.id, employee_categories.name, count(*) as total')
            ->groupBy('employee_categories.id', 'employee_categories.name')
            ->orderBy('employee_categories.name')
            ->get();

        $waitingByGender = Employee::query()
            ->where('status', EmployeeStatus::Waiting)
            ->whereNotNull('gender')
            ->selectRaw('gender, count(*) as total')
            ->groupBy('gender')
            ->pluck('total', 'gender');

        $waitingByLocation = Employee::query()
            ->where('status', EmployeeStatus::Waiting)
            ->whereNotNull('location')
            ->selectRaw('location, count(*) as total')
            ->groupBy('location')
            ->orderByDesc('total')
            ->get();

        $waitingEmployees = Employee::query()->where('status', EmployeeStatus::Waiting)->get(['waiting_since']);
        $now = CarbonImmutable::now();
        $waitingDurationBuckets = ['under_7_days' => 0, 'seven_to_30_days' => 0, 'thirty_to_90_days' => 0, 'over_90_days' => 0, 'unavailable' => 0];
        foreach ($waitingEmployees as $waitingEmployee) {
            if ($waitingEmployee->waiting_since === null) {
                $waitingDurationBuckets['unavailable']++;

                continue;
            }
            $days = CarbonImmutable::parse($waitingEmployee->waiting_since)->diffInDays($now);
            $waitingDurationBuckets[match (true) {
                $days < 7 => 'under_7_days',
                $days < 30 => 'seven_to_30_days',
                $days < 90 => 'thirty_to_90_days',
                default => 'over_90_days',
            }]++;
        }

        // --- Workforce Request Reporting ---
        $workforceRequestsByCompany = WorkforceRequest::query()
            ->join('work_locations', 'work_locations.id', '=', 'workforce_requests.work_location_id')
            ->leftJoin('client_companies', 'client_companies.id', '=', 'work_locations.client_company_id')
            ->selectRaw("coalesce(client_companies.name, 'Fayadhowr Office') as name, count(*) as total")
            ->groupBy('client_companies.id', 'client_companies.name')
            ->orderBy('name')
            ->get();

        $workforceRequestsByGender = WorkforceRequest::query()
            ->selectRaw("coalesce(gender_requirement, 'any') as gender_requirement, count(*) as total")
            ->groupBy('gender_requirement')
            ->pluck('total', 'gender_requirement');

        $workforceRequestsProgress = WorkforceRequest::query()
            ->withCount('matches')
            ->with('workLocation')
            ->orderByDesc('requested_date')
            ->get()
            ->map(fn (WorkforceRequest $request) => [
                'id' => $request->id,
                'work_location_name' => $request->workLocation?->name,
                'status' => $request->status->value,
                'quantity_needed' => $request->quantity_needed,
                'matched_count' => $request->matches_count,
                'unmatched' => max(0, $request->quantity_needed - $request->matches_count),
            ]);

        // --- Active Workforce Reporting ---
        $activeByGender = Employee::query()
            ->where('status', EmployeeStatus::Active)
            ->whereNotNull('gender')
            ->selectRaw('gender, count(*) as total')
            ->groupBy('gender')
            ->pluck('total', 'gender');

        $activeByLocation = Employee::query()
            ->where('status', EmployeeStatus::Active)
            ->whereNotNull('location')
            ->selectRaw('location, count(*) as total')
            ->groupBy('location')
            ->orderByDesc('total')
            ->get();

        // --- Temporary Replacement Reporting ---
        $replacementTotalsByCompany = TemporaryReplacement::query()
            ->join('employee_work_assignments', 'employee_work_assignments.id', '=', 'temporary_replacements.work_assignment_id')
            ->join('work_locations', 'work_locations.id', '=', 'employee_work_assignments.work_location_id')
            ->leftJoin('client_companies', 'client_companies.id', '=', 'work_locations.client_company_id')
            ->leftJoin('temporary_replacement_payments', 'temporary_replacement_payments.temporary_replacement_id', '=', 'temporary_replacements.id')
            ->selectRaw("coalesce(client_companies.name, 'Fayadhowr Office') as name, count(distinct temporary_replacements.id) as total_replacements, coalesce(sum(temporary_replacement_payments.amount), 0) as total_paid")
            ->groupBy('client_companies.id', 'client_companies.name')
            ->orderBy('name')
            ->get();

        // --- Former Employee Reporting ---
        $separationReasonBreakdown = EmployeeSeparation::query()
            ->whereBetween('separation_date', [$from, $to])
            ->selectRaw('reason, count(*) as total')
            ->groupBy('reason')
            ->pluck('total', 'reason');

        $rehireCountInRange = EmployeeStatusHistory::query()
            ->where('from_status', EmployeeStatus::Inactive)
            ->where('to_status', EmployeeStatus::Active)
            ->whereBetween('created_at', [$from, CarbonImmutable::parse($to)->endOfDay()])
            ->count();

        // --- Supervisor Pool Reporting ---
        $supervisorsSinceRange = Employee::query()
            ->where('is_supervisor', true)
            ->whereBetween('supervisor_since', [$from, CarbonImmutable::parse($to)->endOfDay()])
            ->count();

        // --- Office Staff Reporting ---
        $officeStaffByDepartment = Employee::query()
            ->whereHas('activeWorkAssignments.workLocation', fn ($q) => $q->where('location_type', 'office'))
            ->join('departments', 'departments.id', '=', 'employees.department_id')
            ->selectRaw('departments.id, departments.name, count(*) as total')
            ->groupBy('departments.id', 'departments.name')
            ->orderBy('departments.name')
            ->get();

        $officeStaffByPosition = Employee::query()
            ->whereHas('activeWorkAssignments.workLocation', fn ($q) => $q->where('location_type', 'office'))
            ->join('positions', 'positions.id', '=', 'employees.position_id')
            ->selectRaw('positions.id, positions.name, count(*) as total')
            ->groupBy('positions.id', 'positions.name')
            ->orderBy('positions.name')
            ->get();

        // --- Attendance Reporting ---
        $attendanceBreakdown = EmployeeAttendance::query()
            ->whereBetween('date', [$from, $to])
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $attendanceByDepartment = EmployeeAttendance::query()
            ->join('employees', 'employees.id', '=', 'employee_attendances.employee_id')
            ->join('departments', 'departments.id', '=', 'employees.department_id')
            ->whereBetween('employee_attendances.date', [$from, $to])
            ->selectRaw('departments.id, departments.name, count(*) as total')
            ->groupBy('departments.id', 'departments.name')
            ->orderBy('departments.name')
            ->get();

        // --- Performance Reporting ---
        $reviewsByRating = EmployeePerformanceReview::query()
            ->whereBetween('review_date', [$from, $to])
            ->selectRaw('rating, count(*) as total')
            ->groupBy('rating')
            ->pluck('total', 'rating');

        $reviewsByDepartment = EmployeePerformanceReview::query()
            ->join('employees', 'employees.id', '=', 'employee_performance_reviews.employee_id')
            ->join('departments', 'departments.id', '=', 'employees.department_id')
            ->whereBetween('employee_performance_reviews.review_date', [$from, $to])
            ->selectRaw('departments.id, departments.name, count(*) as total')
            ->groupBy('departments.id', 'departments.name')
            ->orderBy('departments.name')
            ->get();

        return [
            'range' => ['from' => $from, 'to' => $to],
            'registrations_in_range' => $registrationsInRange,
            'status_breakdown' => $statusCounts,
            'pipeline_breakdown' => $pipelineCounts,
            'category_breakdown' => $categoryCounts,
            'department_breakdown' => $departmentCounts,
            'workplace_breakdown' => $workplaceBreakdown,
            'company_salary_totals' => $companySalaryTotals,
            'workforce_request_breakdown' => $workforceRequestBreakdown,
            'payment_totals_by_department' => $paymentTotalsByDepartment,
            'penalty_totals_by_department' => $penaltyTotalsByDepartment,
            'advance_totals_by_department' => $advanceTotalsByDepartment,
            'waiting_by_category' => $waitingByCategory,
            'waiting_by_gender' => $waitingByGender,
            'waiting_by_location' => $waitingByLocation,
            'waiting_duration_buckets' => $waitingDurationBuckets,
            'workforce_requests_by_company' => $workforceRequestsByCompany,
            'workforce_requests_by_gender_requirement' => $workforceRequestsByGender,
            'workforce_requests_progress' => $workforceRequestsProgress,
            'active_by_gender' => $activeByGender,
            'active_by_location' => $activeByLocation,
            'replacement_totals_by_company' => $replacementTotalsByCompany,
            'separation_reason_breakdown' => $separationReasonBreakdown,
            'rehire_count_in_range' => $rehireCountInRange,
            'supervisors_since_range' => $supervisorsSinceRange,
            'office_staff_by_department' => $officeStaffByDepartment,
            'office_staff_by_position' => $officeStaffByPosition,
            'attendance_breakdown' => $attendanceBreakdown,
            'attendance_by_department' => $attendanceByDepartment,
            'reviews_by_rating' => $reviewsByRating,
            'reviews_by_department' => $reviewsByDepartment,
        ];
    }
}
