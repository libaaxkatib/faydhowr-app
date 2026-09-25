<?php

namespace App\Actions\Hr;

use App\Enums\AttendanceStatus;
use App\Enums\EmployeePipelineStage;
use App\Enums\EmployeeStatus;
use App\Enums\TemporaryReplacementStatus;
use App\Enums\WorkforceRequestStatus;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeCategory;
use App\Models\EmployeePayment;
use App\Models\EmployeePenalty;
use App\Models\TemporaryReplacement;
use App\Models\WorkforceRequest;
use Carbon\CarbonImmutable;

/**
 * Real Eloquent counts only, per docs/HRM_MARKETING_SRS.md §28 — no fabricated
 * statistics. Categories/counts are 0 where there is no data yet.
 */
class GetHrDashboardAction
{
    public function handle(): array
    {
        $now = CarbonImmutable::now();
        $monthStart = $now->startOfMonth()->toDateString();
        $monthEnd = $now->endOfMonth()->toDateString();

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

        $newApplicants = Employee::query()
            ->where('status', EmployeeStatus::Applicant->value)
            ->where('application_date', '>=', CarbonImmutable::now()->subDays(30)->toDateString())
            ->count();

        $pipelineCounts = Employee::query()
            ->whereNotNull('pipeline_stage')
            ->selectRaw('pipeline_stage, count(*) as total')
            ->groupBy('pipeline_stage')
            ->pluck('total', 'pipeline_stage');

        return [
            'total_employees' => Employee::query()->count(),
            'active_employees' => (int) ($statusCounts[EmployeeStatus::Active->value] ?? 0),
            'inactive_employees' => (int) ($statusCounts[EmployeeStatus::Inactive->value] ?? 0),
            'new_applicants' => $newApplicants,
            'practical' => (int) ($statusCounts[EmployeeStatus::Practical->value] ?? 0),
            'waiting' => (int) ($statusCounts[EmployeeStatus::Waiting->value] ?? 0),
            'approved' => (int) ($statusCounts[EmployeeStatus::Approved->value] ?? 0),
            'category_breakdown' => $categoryCounts,
            'damiin_needed' => (int) ($pipelineCounts[EmployeePipelineStage::DamiinNeeded->value] ?? 0),
            'contract_pending' => (int) ($pipelineCounts[EmployeePipelineStage::ContractPending->value] ?? 0),
            'uniform_pending' => (int) ($pipelineCounts[EmployeePipelineStage::UniformPending->value] ?? 0),
            'need_training' => (int) ($pipelineCounts[EmployeePipelineStage::NeedTraining->value] ?? 0),
            'need_practical' => (int) ($pipelineCounts[EmployeePipelineStage::NeedPractical->value] ?? 0),
            'practical_repeat' => (int) ($pipelineCounts[EmployeePipelineStage::PracticalRepeat->value] ?? 0),
            'rejected' => (int) ($pipelineCounts[EmployeePipelineStage::Rejected->value] ?? 0),
            'open_workforce_requests' => WorkforceRequest::query()
                ->whereIn('status', [WorkforceRequestStatus::Open, WorkforceRequestStatus::PartiallyFilled])
                ->count(),
            'supervisor_pool_count' => Employee::query()->where('is_supervisor', true)->count(),
            'active_temporary_replacements' => TemporaryReplacement::query()
                ->where('status', TemporaryReplacementStatus::Active)
                ->count(),
            'office_staff_count' => Employee::query()
                ->whereHas('activeWorkAssignments.workLocation', fn ($q) => $q->where('location_type', 'office'))
                ->count(),
            'attendance_marked_today' => EmployeeAttendance::query()
                ->whereDate('date', CarbonImmutable::now()->toDateString())
                ->count(),
            'present_today' => EmployeeAttendance::query()
                ->whereDate('date', CarbonImmutable::now()->toDateString())
                ->where('status', AttendanceStatus::Present)
                ->count(),
            'absent_today' => EmployeeAttendance::query()
                ->whereDate('date', CarbonImmutable::now()->toDateString())
                ->where('status', AttendanceStatus::Absent)
                ->count(),
            'late_today' => EmployeeAttendance::query()
                ->whereDate('date', CarbonImmutable::now()->toDateString())
                ->where('status', AttendanceStatus::Late)
                ->count(),
            'fulfilled_workforce_requests' => WorkforceRequest::query()
                ->where('status', WorkforceRequestStatus::Fulfilled)
                ->count(),
            'client_company_active_employees' => Employee::query()
                ->whereHas('activeWorkAssignments.workLocation', fn ($q) => $q->where('location_type', 'client'))
                ->count(),
            'month_payments_total' => (string) EmployeePayment::query()
                ->whereBetween('payment_date', [$monthStart, $monthEnd])
                ->sum('amount'),
            'month_penalties_total' => (string) EmployeePenalty::query()
                ->whereBetween('penalty_date', [$monthStart, $monthEnd])
                ->sum('deduction_amount'),
            'month_advances_total' => (string) EmployeeAdvance::query()
                ->whereBetween('advance_date', [$monthStart, $monthEnd])
                ->sum('amount'),
        ];
    }
}
