<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Enums\AdminRole;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\HrComplianceFinding;
use App\Support\ApiResponse;
use App\Support\Hr\Compliance\ComplianceReport;
use App\Support\Hr\Compliance\ComplianceScanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * HR compliance and accountability report. For the HR Manager and Super Admin only (the route needs hr.reports.view; the role is checked here as well,
 * because the report names the people who allowed work to start): it shows who works while a defined requirement is unmet and who is on record as
 * responsible, and lets the HR Manager track the follow-up. Owner decision UG-1: every GET here (list, filters, search, pages, one finding) is strictly
 * READ-ONLY - it creates no finding, rewrites no timestamp, resolves nothing and writes no audit row. Scanning the live records happens only through the
 * explicit scan() action (hr.manage, throttled, audited); there is no schedule. Nothing here changes an employee, assignment, status, salary or payroll
 * record. Changing a finding's status or note needs hr.manage too and is audited.
 */
class ComplianceController extends Controller
{
    public function __construct(private readonly ComplianceReport $report) {}

    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->deny($request)) {
            return $denied;
        }

        $filters = $request->validate([
            'category' => ['nullable', Rule::in(ComplianceScanner::categories())],
            'severity' => ['nullable', Rule::in(['high', 'medium', 'low'])],
            'status' => ['nullable', Rule::in(HrComplianceFinding::statuses())],
            'confidence' => ['nullable', Rule::in([HrComplianceFinding::CONFIRMED, HrComplianceFinding::NEEDS_REVIEW])],
            'employment_type' => ['nullable', Rule::in(['Permanent', 'Temporary', 'Replacement'])],
            'client_company_id' => ['nullable', 'integer', 'min:1'],
            'employee_id' => ['nullable', 'integer', 'min:1'],
            'responsible_admin_id' => ['nullable', 'regex:/^(none|\d+)$/'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = $this->report->query($filters)->paginate((int) ($filters['per_page'] ?? 50));

        return ApiResponse::success('HR compliance report retrieved successfully.', [
            'summary' => $this->report->summary($this->report->employeesReviewed()),
            'last_scan' => $this->report->lastScan(),
            'findings' => $page->getCollection()->map(fn (HrComplianceFinding $f): array => $this->present($f))->all(),
            'filter_options' => [
                'companies' => HrComplianceFinding::query()->whereNotNull('client_company_id')->select('client_company_id', 'company_name')->distinct()->orderBy('company_name')->get()
                    ->map(fn ($r): array => ['id' => (int) $r->client_company_id, 'name' => $r->company_name])->all(),
                'responsible' => Admin::query()->whereIn('id', HrComplianceFinding::query()->whereNotNull('responsible_admin_id')->select('responsible_admin_id'))->orderBy('full_name')->get(['id', 'full_name'])
                    ->map(fn (Admin $a): array => ['id' => $a->id, 'name' => $a->full_name])->all(),
            ],
        ], 200, ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]);
    }

    /**
     * POST hr/compliance/scan - the ONLY place a scan runs. Stores new/changed findings, marks cleared ones Resolved by the system, and records who ran it.
     * A second scan started while one is running is refused instead of queued.
     */
    public function scan(Request $request): JsonResponse
    {
        if ($denied = $this->deny($request)) {
            return $denied;
        }

        $lock = Cache::lock('hr-compliance-scan', 300);

        if (! $lock->get()) {
            return ApiResponse::error('A compliance scan is already running. Wait a moment and open the report again.', 'COMPLIANCE_SCAN_RUNNING', 409);
        }

        try {
            $result = $this->report->sync($request->user());
        } finally {
            $lock->release();
        }

        return ApiResponse::success('HR compliance scan finished.', [
            'scan' => ['new' => $result['new'], 'reopened' => $result['reopened'], 'cleared' => $result['cleared'], 'employees_reviewed' => $result['reviewed']],
            'summary' => $this->report->summary($result['reviewed']),
            'last_scan' => $this->report->lastScan(),
        ]);
    }

    public function show(Request $request, HrComplianceFinding $finding): JsonResponse
    {
        if ($denied = $this->deny($request)) {
            return $denied;
        }

        return ApiResponse::success('HR compliance finding retrieved successfully.', [...$this->present($finding->load('responsible:id,full_name,role')), 'history' => $this->report->history($finding)]);
    }

    public function update(Request $request, HrComplianceFinding $finding): JsonResponse
    {
        if ($denied = $this->deny($request)) {
            return $denied;
        }

        $data = $request->validate([
            'status' => ['required', Rule::in(HrComplianceFinding::statuses())],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $updated = $this->report->updateFollowUp($finding, $request->user(), $data['status'], $data['note'] ?? null);

        return ApiResponse::success('HR compliance finding updated successfully.', [...$this->present($updated->load('responsible:id,full_name,role')), 'history' => $this->report->history($updated)]);
    }

    private function deny(Request $request): ?JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof Admin || ! in_array($user->role, [AdminRole::SuperAdmin, AdminRole::HrManager], true)) {
            return ApiResponse::error('The HR compliance report is reserved for the HR Manager and Super Admin.', 'FORBIDDEN', 403);
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function present(HrComplianceFinding $f): array
    {
        return [
            'id' => $f->id,
            'employee_id' => $f->employee_id,
            'employee_name' => $f->employee_name,
            'employee_number' => $f->employee_number,
            'client_company_id' => $f->client_company_id,
            'company' => $f->company_name,
            'position' => $f->position_name,
            'shift' => $f->shift,
            'employment_type' => $f->employment_type,
            'employment_status' => $f->employment_status,
            'assignment_id' => $f->assignment_id,
            'assignment_status' => $f->assignment_status,
            'category' => $f->category,
            'code' => $f->code,
            'severity' => $f->severity,
            'confidence' => $f->confidence,
            'requirement' => $f->requirement,
            'explanation' => $f->explanation,
            'recommended_action' => $f->recommended_action,
            'details' => $f->details,
            // null = no record names anybody: the screen says "Responsible manager not recorded"; nobody is guessed.
            'responsible' => $f->responsible === null ? null : [
                'id' => $f->responsible->id,
                'name' => $f->responsible->full_name,
                'role' => $f->responsible->role?->value,
                'action' => $f->responsible_action,
                'at' => $f->responsible_at?->toIso8601String(),
            ],
            'appointed_on' => $f->appointed_on?->toDateString(),
            'detected_on' => $f->first_detected_at?->toDateString(),
            'last_detected_at' => $f->last_detected_at?->toIso8601String(),
            'status' => $f->status,
            'resolution_source' => $f->resolution_source,
            'resolved_at' => $f->resolved_at?->toIso8601String(),
            'note' => $f->note,
        ];
    }
}
