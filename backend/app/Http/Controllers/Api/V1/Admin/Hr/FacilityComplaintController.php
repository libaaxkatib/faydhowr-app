<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Actions\Hr\Facility\RecordFacilityComplaintSatisfactionAction;
use App\Actions\Hr\Facility\StoreFacilityComplaintAction;
use App\Actions\Hr\Facility\TransitionFacilityComplaintAction;
use App\Actions\Hr\Facility\UpdateFacilityComplaintAction;
use App\Actions\Hr\Facility\VoidFacilityComplaintAction;
use App\Enums\FacilityComplaintStatus;
use App\Enums\FacilityComplaintType;
use App\Enums\FacilitySatisfaction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Hr\Facility\FacilityComplaintSatisfactionRequest;
use App\Http\Requests\Api\V1\Admin\Hr\Facility\FacilityVoidRequest;
use App\Http\Requests\Api\V1\Admin\Hr\Facility\StoreFacilityComplaintRequest;
use App\Http\Requests\Api\V1\Admin\Hr\Facility\TransitionFacilityComplaintRequest;
use App\Http\Requests\Api\V1\Admin\Hr\Facility\UpdateFacilityComplaintRequest;
use App\Models\FacilityComplaint;
use App\Support\ApiResponse;
use App\Support\Hr\Facility\FacilityPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FacilityComplaintController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $v = $request->validate([
            'client_company_id' => ['sometimes', 'nullable', 'integer'],
            'type' => ['sometimes', 'nullable', Rule::in(FacilityComplaintType::values())],
            'status' => ['sometimes', 'nullable', Rule::in([...FacilityComplaintStatus::values(), 'unresolved'])],
            'satisfaction' => ['sometimes', 'nullable', Rule::in([...FacilitySatisfaction::values(), 'not_recorded'])],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
            'include_voided' => ['sometimes', 'nullable', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = FacilityComplaint::query()
            ->with('clientCompany:id,name,category', 'reporterEmployee:id,employee_number,full_name')
            ->when(! ($v['include_voided'] ?? false), fn ($q) => $q->counted())
            ->when(! empty($v['client_company_id']), fn ($q) => $q->where('client_company_id', $v['client_company_id']))
            ->when(! empty($v['type']), fn ($q) => $q->where('type', $v['type']))
            ->when(! empty($v['status']), fn ($q) => $v['status'] === 'unresolved' ? $q->where('status', '!=', FacilityComplaintStatus::Resolved->value) : $q->where('status', $v['status']))
            ->when(! empty($v['satisfaction']), fn ($q) => $v['satisfaction'] === 'not_recorded' ? $q->whereNull('satisfaction') : $q->where('satisfaction', $v['satisfaction']))
            ->when(! empty($v['from']), fn ($q) => $q->whereDate('reported_on', '>=', $v['from']))
            ->when(! empty($v['to']), fn ($q) => $q->whereDate('reported_on', '<=', $v['to']))
            ->orderByDesc('reported_on')->orderByDesc('id');

        $paginator = $query->paginate((int) ($v['per_page'] ?? 25));

        return ApiResponse::success(
            'Facility complaints retrieved successfully.',
            array_map(fn (FacilityComplaint $c): array => FacilityPresenter::complaint($c), $paginator->items()),
            200,
            ['current_page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'last_page' => $paginator->lastPage()],
        );
    }

    public function show(FacilityComplaint $facilityComplaint): JsonResponse
    {
        return ApiResponse::success('Facility complaint retrieved successfully.', FacilityPresenter::complaint($facilityComplaint, true));
    }

    public function store(StoreFacilityComplaintRequest $request, StoreFacilityComplaintAction $action): JsonResponse
    {
        return ApiResponse::success('Complaint registered successfully.', FacilityPresenter::complaint($action->handle($request->validated(), $request->user()), true), 201);
    }

    public function update(UpdateFacilityComplaintRequest $request, FacilityComplaint $facilityComplaint, UpdateFacilityComplaintAction $action): JsonResponse
    {
        return ApiResponse::success('Complaint updated successfully.', FacilityPresenter::complaint($action->handle($facilityComplaint, $request->validated(), $request->user()), true));
    }

    public function transition(TransitionFacilityComplaintRequest $request, FacilityComplaint $facilityComplaint, TransitionFacilityComplaintAction $action): JsonResponse
    {
        $complaint = $action->handle(
            $facilityComplaint,
            FacilityComplaintStatus::from((string) $request->validated('status')),
            $request->validated('note'),
            $request->validated('resolved_on'),
            $request->user(),
        );

        return ApiResponse::success('Complaint status updated.', FacilityPresenter::complaint($complaint, true));
    }

    public function satisfaction(FacilityComplaintSatisfactionRequest $request, FacilityComplaint $facilityComplaint, RecordFacilityComplaintSatisfactionAction $action): JsonResponse
    {
        $v = $request->validated();
        $complaint = $action->handle($facilityComplaint, $v['satisfaction'] === null ? null : FacilitySatisfaction::from($v['satisfaction']), $v['evidence'], $v['recorded_on'] ?? null, $request->user());

        return ApiResponse::success('Satisfaction feedback saved.', FacilityPresenter::complaint($complaint, true));
    }

    public function void(FacilityVoidRequest $request, FacilityComplaint $facilityComplaint, VoidFacilityComplaintAction $action): JsonResponse
    {
        return ApiResponse::success('Complaint voided.', FacilityPresenter::complaint($action->handle($facilityComplaint, (string) $request->validated('reason'), $request->user()), true));
    }
}
