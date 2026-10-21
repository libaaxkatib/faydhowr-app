<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Actions\Hr\Facility\SetFacilityInspectionScheduleAction;
use App\Actions\Hr\Facility\StoreFacilityInspectionAction;
use App\Actions\Hr\Facility\UpdateFacilityInspectionAction;
use App\Actions\Hr\Facility\VoidFacilityInspectionAction;
use App\Enums\FacilityDueState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Hr\Facility\FacilityVoidRequest;
use App\Http\Requests\Api\V1\Admin\Hr\Facility\SetFacilityScheduleRequest;
use App\Http\Requests\Api\V1\Admin\Hr\Facility\StoreFacilityInspectionRequest;
use App\Http\Requests\Api\V1\Admin\Hr\Facility\UpdateFacilityInspectionRequest;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Models\FacilityInspection;
use App\Support\ApiResponse;
use App\Support\Hr\Facility\FacilityPresenter;
use App\Support\Hr\Facility\FacilityStatusBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FacilityInspectionController extends Controller
{
    /** Inspection history, newest first, filterable by company, period and whether anything was found. */
    public function index(Request $request): JsonResponse
    {
        $v = $request->validate([
            'client_company_id' => ['sometimes', 'nullable', 'integer'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
            'has_issues' => ['sometimes', 'nullable', 'boolean'],
            'include_voided' => ['sometimes', 'nullable', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = FacilityInspection::query()
            ->with('clientCompany:id,name,category', 'workLocation:id,name', 'recordedBy:id,full_name', 'items:id,facility_inspection_id,result', 'uniformIssues:id,facility_inspection_id')
            ->withCount([
                'complaints as staff_complaints_count' => fn ($c) => $c->counted()->where('type', 'staff'),
                'complaints as customer_complaints_count' => fn ($c) => $c->counted()->where('type', 'customer'),
            ])
            ->when(! ($v['include_voided'] ?? false), fn ($q) => $q->counted())
            ->when(! empty($v['client_company_id']), fn ($q) => $q->where('client_company_id', $v['client_company_id']))
            ->when(! empty($v['from']), fn ($q) => $q->whereDate('inspected_on', '>=', $v['from']))
            ->when(! empty($v['to']), fn ($q) => $q->whereDate('inspected_on', '<=', $v['to']))
            ->when(array_key_exists('has_issues', $v) && $v['has_issues'] !== null, function ($q) use ($v) {
                $with = (bool) $v['has_issues'];
                $found = fn ($inner) => $inner->whereHas('items', fn ($i) => $i->where('result', 'unsatisfactory'))->orWhereHas('uniformIssues');

                return $with ? $q->where($found) : $q->whereNot($found);
            })
            ->orderByDesc('inspected_on')->orderByDesc('id');

        $paginator = $query->paginate((int) ($v['per_page'] ?? 25));

        return ApiResponse::success(
            'Facility inspections retrieved successfully.',
            array_map(fn (FacilityInspection $i): array => FacilityPresenter::inspection($i), $paginator->items()),
            200,
            ['current_page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'last_page' => $paginator->lastPage()],
        );
    }

    public function show(FacilityInspection $facilityInspection): JsonResponse
    {
        return ApiResponse::success('Facility inspection retrieved successfully.', FacilityPresenter::inspection($facilityInspection->load('items', 'uniformIssues.employee'), true));
    }

    public function store(StoreFacilityInspectionRequest $request, StoreFacilityInspectionAction $action): JsonResponse
    {
        $inspection = $action->handle($request->validated(), $request->user());

        return ApiResponse::success('Facility inspection recorded successfully.', FacilityPresenter::inspection($inspection, true), 201);
    }

    public function update(UpdateFacilityInspectionRequest $request, FacilityInspection $facilityInspection, UpdateFacilityInspectionAction $action): JsonResponse
    {
        $inspection = $action->handle($facilityInspection, $request->validated(), $request->user());

        return ApiResponse::success('Facility inspection updated successfully.', FacilityPresenter::inspection($inspection, true));
    }

    public function void(FacilityVoidRequest $request, FacilityInspection $facilityInspection, VoidFacilityInspectionAction $action): JsonResponse
    {
        $inspection = $action->handle($facilityInspection, (string) $request->validated('reason'), $request->user());

        return ApiResponse::success('Facility inspection voided.', FacilityPresenter::inspection($inspection->load('items', 'uniformIssues.employee'), true));
    }

    /** The schedule board: one row per active company with its frequency, last/next dates, count and today's state, computed when asked. */
    public function status(Request $request, FacilityStatusBuilder $builder): JsonResponse
    {
        $v = $request->validate([
            'state' => ['sometimes', 'nullable', Rule::in(FacilityDueState::values())],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'category' => ['sometimes', 'nullable', Rule::in(['company', 'guri'])],
            'client_company_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $result = $builder->build(null, $v);

        return ApiResponse::success('Facility inspection status retrieved successfully.', $result['rows'], 200, [
            'counts' => $result['counts'],
            'today' => $result['today'],
            // Said plainly so nobody reads a display state as a delivered reminder: nothing is sent automatically.
            'reminders' => 'Due and overdue states are calculated when this list is opened. No automatic reminder is sent.',
        ]);
    }

    /**
     * The people who may be named in a uniform issue: employees assigned to this company on the given date (default today). Names and
     * employee numbers only; the same rule is enforced again when the inspection is saved.
     */
    public function companyEmployees(Request $request, ClientCompany $clientCompany): JsonResponse
    {
        $v = $request->validate(['on' => ['sometimes', 'nullable', 'date']]);
        $date = $v['on'] ?? now()->toDateString();

        $ids = EmployeeWorkAssignment::query()
            ->where('status', '!=', 'cancelled')
            ->where(fn ($q) => $q->where('client_company_id', $clientCompany->id)
                ->orWhereHas('workLocation', fn ($w) => $w->where('client_company_id', $clientCompany->id)))
            ->whereDate('start_date', '<=', $date)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $date))
            ->pluck('employee_id')->unique()->all();

        $employees = Employee::query()->whereIn('id', $ids ?: [0])->orderBy('full_name')->get(['id', 'employee_number', 'full_name'])
            ->map(fn (Employee $e): array => ['id' => $e->id, 'employee_number' => $e->employee_number, 'full_name' => $e->full_name])->all();

        return ApiResponse::success('Employees assigned to this company retrieved successfully.', $employees, 200, ['on' => $date]);
    }

    public function setSchedule(SetFacilityScheduleRequest $request, ClientCompany $clientCompany, SetFacilityInspectionScheduleAction $action): JsonResponse
    {
        $schedule = $action->handle($clientCompany, $request->validated(), $request->user());

        return ApiResponse::success('Inspection frequency saved.', [
            'client_company_id' => $clientCompany->id,
            'frequency_days' => $schedule->frequency_days,
            'due_soon_days' => $schedule->due_soon_days,
            'notes' => $schedule->notes,
        ]);
    }
}
