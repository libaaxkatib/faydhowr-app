<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Actions\Hr\CompanyEmployees\AssignEmployeeToCompanyAction;
use App\Actions\Hr\CompanyEmployees\ListCompanyEmployeeHistoryAction;
use App\Actions\Hr\CompanyEmployees\ListCompanyEmployeesAction;
use App\Actions\Hr\CompanyEmployees\ReopenEndedAssignmentAction;
use App\Actions\Hr\CompanyEmployees\ReplaceCompanyEmployeeAction;
use App\Actions\Hr\CompanyEmployees\ReturnCompanyEmployeeAction;
use App\Actions\Hr\CompanyEmployees\SearchCompanyEmployeeCandidatesAction;
use App\Actions\Hr\CompanyEmployees\StopCompanyAssignmentAction;
use App\Actions\Hr\UpdateWorkAssignmentAction;
use App\Enums\AdminPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Hr\CompanyEmployees\AssignCompanyEmployeeRequest;
use App\Http\Requests\Api\V1\Admin\Hr\CompanyEmployees\EditCompanyAssignmentRequest;
use App\Http\Requests\Api\V1\Admin\Hr\CompanyEmployees\ListCompanyEmployeesRequest;
use App\Http\Requests\Api\V1\Admin\Hr\CompanyEmployees\ReopenCompanyAssignmentRequest;
use App\Http\Requests\Api\V1\Admin\Hr\CompanyEmployees\ReplaceCompanyEmployeeRequest;
use App\Http\Requests\Api\V1\Admin\Hr\CompanyEmployees\ReturnCompanyEmployeeRequest;
use App\Http\Requests\Api\V1\Admin\Hr\CompanyEmployees\StopCompanyAssignmentRequest;
use App\Http\Resources\Api\V1\Admin\Hr\WorkAssignmentResource;
use App\Models\AssignmentAbsence;
use App\Models\ClientCompany;
use App\Models\EmployeeWorkAssignment;
use App\Models\WorkLocation;
use App\Support\AdminPermissionResolver;
use App\Support\ApiResponse;
use App\Support\Hr\CompanyEmployees\CompanyAssignmentGuard;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Company Employee Management: the employees of ONE company, and the actions on their assignments. Reading needs hr.view; every
 * change needs hr.manage AND hr.view (routes). Pay figures (calculated pay, payment status) are returned only to callers who also
 * hold hr.payroll.view. Every assignment named in a URL is re-checked to belong to the company in that URL.
 */
class CompanyEmployeeController extends Controller
{
    public function index(ListCompanyEmployeesRequest $request, ClientCompany $clientCompany, ListCompanyEmployeesAction $action, AdminPermissionResolver $permissions): JsonResponse
    {
        $withPayroll = $permissions->has($request->user(), AdminPermission::HrPayrollView->value, $request);
        $period = $request->validated('period') ?: CarbonImmutable::now()->format('Y-m');

        return ApiResponse::success('Company employees retrieved successfully.', $action->handle($clientCompany, $period, $request->validated(), $withPayroll));
    }

    public function candidates(Request $request, ClientCompany $clientCompany, SearchCompanyEmployeeCandidatesAction $action, AdminPermissionResolver $permissions): JsonResponse
    {
        // Whether the person searching may activate someone from the picker is the same permission the Activate / Rehire endpoints themselves demand.
        $canActivate = $permissions->has($request->user(), AdminPermission::HrManage->value, $request);

        return ApiResponse::success('Employees retrieved successfully.', $action->handle($clientCompany, (string) $request->query('q', ''), $canActivate));
    }

    public function history(Request $request, ClientCompany $clientCompany, ListCompanyEmployeeHistoryAction $action): JsonResponse
    {
        return ApiResponse::success('Assignment history retrieved successfully.', $action->handle($clientCompany, $request->filled('employee_id') ? (int) $request->query('employee_id') : null));
    }

    public function store(AssignCompanyEmployeeRequest $request, ClientCompany $clientCompany, AssignEmployeeToCompanyAction $action): JsonResponse
    {
        $this->assertLocationBelongsTo($clientCompany, $request->validated('work_location_id'));

        $assignment = $action->handle($clientCompany, $request->validated(), $request->user());

        return ApiResponse::success('Employee added to the company.', new WorkAssignmentResource($assignment->load(['employee', 'position'])), 201);
    }

    public function update(EditCompanyAssignmentRequest $request, ClientCompany $clientCompany, EmployeeWorkAssignment $workAssignment, UpdateWorkAssignmentAction $action): JsonResponse
    {
        CompanyAssignmentGuard::assignmentOf($clientCompany, $workAssignment);

        if ($workAssignment->status->value === 'cancelled') {
            CompanyAssignmentGuard::fail('A cancelled assignment cannot be edited.', 'ASSIGNMENT_CANCELLED');
        }

        $assignment = $action->handle($workAssignment, $request->validated(), $request->user());

        return ApiResponse::success('Assignment updated.', new WorkAssignmentResource($assignment->load(['employee', 'position'])));
    }

    public function replace(ReplaceCompanyEmployeeRequest $request, ClientCompany $clientCompany, EmployeeWorkAssignment $workAssignment, ReplaceCompanyEmployeeAction $action): JsonResponse
    {
        CompanyAssignmentGuard::assignmentOf($clientCompany, $workAssignment);

        $result = $action->handle($clientCompany, $workAssignment, $request->validated(), $request->user());

        return ApiResponse::success('Employee replaced.', [
            'original' => new WorkAssignmentResource($result['original']),
            'replacement' => new WorkAssignmentResource($result['replacement']),
            'absence' => isset($result['absence']) ? $this->absenceSummary($result['absence']) : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function absenceSummary(AssignmentAbsence $absence): array
    {
        return [
            'id' => $absence->id,
            'assignment_id' => $absence->work_assignment_id,
            'last_working_day' => $absence->last_working_day->toDateString(),
            'absent_from' => $absence->absent_from->toDateString(),
            'returned_on' => $absence->returned_on?->toDateString(),
            'terminated_on' => $absence->terminated_on?->toDateString(),
        ];
    }

    public function returnEmployee(ReturnCompanyEmployeeRequest $request, ClientCompany $clientCompany, EmployeeWorkAssignment $workAssignment, ReturnCompanyEmployeeAction $action): JsonResponse
    {
        CompanyAssignmentGuard::assignmentOf($clientCompany, $workAssignment);

        $result = $action->handle($clientCompany, $workAssignment, $request->validated(), $request->user());

        return ApiResponse::success('Original employee returned.', [
            'replacement' => new WorkAssignmentResource($result['replacement']),
            'returned' => new WorkAssignmentResource($result['returned']),
            'absence' => isset($result['absence']) ? $this->absenceSummary($result['absence']) : null,
        ]);
    }

    public function stop(StopCompanyAssignmentRequest $request, ClientCompany $clientCompany, EmployeeWorkAssignment $workAssignment, StopCompanyAssignmentAction $action): JsonResponse
    {
        CompanyAssignmentGuard::assignmentOf($clientCompany, $workAssignment);

        $assignment = $action->handle($clientCompany, $workAssignment, $request->validated(), $request->user());

        return ApiResponse::success('Assignment stopped. The employee\'s HR status was not changed.', [
            ...(new WorkAssignmentResource($assignment))->resolve($request),
            'employee_status' => $assignment->employee?->status->value,
        ]);
    }

    /** Undo a Stop / End that was a mistake: the same assignment is active again (nothing is recreated). */
    public function reopen(ReopenCompanyAssignmentRequest $request, ClientCompany $clientCompany, EmployeeWorkAssignment $workAssignment, ReopenEndedAssignmentAction $action): JsonResponse
    {
        CompanyAssignmentGuard::assignmentOf($clientCompany, $workAssignment);

        $assignment = $action->handle($clientCompany, $workAssignment, $request->validated(), $request->user());

        return ApiResponse::success('Assignment reopened. The employee\'s HR status was not changed.', [
            ...(new WorkAssignmentResource($assignment))->resolve($request),
            'employee_status' => $assignment->employee?->status->value,
        ]);
    }

    private function assertLocationBelongsTo(ClientCompany $company, mixed $locationId): void
    {
        if ($locationId === null) {
            return;
        }

        $location = WorkLocation::query()->find((int) $locationId);

        if ($location === null || (int) $location->client_company_id !== (int) $company->id) {
            CompanyAssignmentGuard::fail('That work location does not belong to this company.', 'LOCATION_NOT_IN_COMPANY');
        }
    }
}
